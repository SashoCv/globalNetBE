<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ClinicNewRegistrationAdminMail;
use App\Mail\ClinicRegisteredMail;
use App\Mail\ClinicResetPasswordMail;
use App\Models\ShopClinic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ClinicAuthController extends Controller
{
    // POST /api/clinic/register
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'edb' => 'nullable|string|max:32',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'required|email|max:255|unique:shop_clinics,email',
            'phone' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:500',
            'description' => 'nullable|string',
            'password' => 'required|string|min:8|confirmed',
            'current_status' => 'required|file|mimes:pdf,jpg,jpeg,png|max:8192',
        ]);

        $documentPath = $request->file('current_status')->store('clinic-documents', 'local');

        $clinic = ShopClinic::create([
            'name' => $validated['name'],
            'edb' => $validated['edb'] ?? null,
            'contact_person' => $validated['contact_person'] ?? null,
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'city' => $validated['city'] ?? null,
            'address' => $validated['address'] ?? null,
            'description' => $validated['description'] ?? null,
            'password' => $validated['password'], // cast 'hashed' auto-hashes
            'current_status_document' => $documentPath,
            'status' => 'pending',
        ]);

        // Confirmation to the clinic + notification to the admin.
        // Wrapped so a mail/SMTP failure never breaks registration itself.
        try {
            Mail::to($clinic->email)->send(new ClinicRegisteredMail($clinic));

            $adminEmail = config('app.shop_admin_email');
            if ($adminEmail) {
                Mail::to($adminEmail)->send(new ClinicNewRegistrationAdminMail($clinic));
            }
        } catch (\Throwable $e) {
            Log::error('[clinic register] mail failed', [
                'clinic_id' => $clinic->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Регистрацијата е примена. По одобрување од администратор ќе можете да се најавите.',
            'clinic' => $this->publicClinic($clinic),
        ], 201);
    }

    // POST /api/clinic/login
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $clinic = ShopClinic::where('email', $validated['email'])->first();

        if (!$clinic || !$clinic->password || !Hash::check($validated['password'], $clinic->password)) {
            return response()->json([
                'message' => 'Неточна е-пошта или лозинка.',
            ], 401);
        }

        if ($clinic->status === 'pending') {
            return response()->json([
                'message' => 'Вашата регистрација сè уште чека одобрување од администратор.',
                'reason' => 'pending',
            ], 403);
        }
        if ($clinic->status === 'rejected') {
            return response()->json([
                'message' => 'Вашата регистрација е одбиена. За повеќе информации контактирајте го администраторот.',
                'reason' => 'rejected',
                'admin_note' => $clinic->admin_note,
            ], 403);
        }

        $clinic->update(['last_login_at' => now()]);
        $token = $clinic->createToken('shop-clinic')->plainTextToken;

        return response()->json([
            'token' => $token,
            'clinic' => $this->publicClinic($clinic),
        ]);
    }

    // GET /api/clinic/me
    public function me(Request $request): JsonResponse
    {
        $clinic = $request->user();
        if (!$clinic instanceof ShopClinic) {
            return response()->json(['message' => 'Не сте најавени.'], 401);
        }
        return response()->json($this->publicClinic($clinic));
    }

    // POST /api/clinic/logout
    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();
        return response()->json(['message' => 'Успешна одјава.']);
    }

    // POST /api/clinic/forgot-password
    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $clinic = ShopClinic::where('email', $validated['email'])->first();

        // Always return success-style response so we don't leak which emails exist
        $generic = [
            'message' => 'Доколку постои аккаунт со таа е-пошта, ќе добиете линк за ресетирање.',
        ];

        if (!$clinic || $clinic->status === 'rejected') {
            return response()->json($generic);
        }

        $token = Str::random(64);
        $clinic->update([
            'password_reset_token' => Hash::make($token),
            'password_reset_expires_at' => now()->addHour(),
        ]);

        $resetUrl = config('app.shop_url', 'http://localhost:5193') . '/reset-password?token=' . $token . '&email=' . urlencode($clinic->email);

        try {
            Mail::to($clinic->email)->send(new ClinicResetPasswordMail($clinic, $resetUrl));
        } catch (\Throwable $e) {
            Log::error('[clinic password reset] mail failed', [
                'email' => $clinic->email,
                'error' => $e->getMessage(),
            ]);
        }

        $payload = $generic;
        if (app()->environment(['local', 'testing'])) {
            $payload['dev_reset_url'] = $resetUrl;
            $payload['dev_token'] = $token;
        }
        return response()->json($payload);
    }

    // POST /api/clinic/reset-password
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $clinic = ShopClinic::where('email', $validated['email'])->first();
        if (!$clinic || !$clinic->password_reset_token || !$clinic->password_reset_expires_at) {
            return response()->json(['message' => 'Невалиден или истечен линк.'], 422);
        }
        if ($clinic->password_reset_expires_at->isPast()) {
            return response()->json(['message' => 'Линкот за ресетирање истече. Побарајте нов.'], 422);
        }
        if (!Hash::check($validated['token'], $clinic->password_reset_token)) {
            return response()->json(['message' => 'Невалиден линк.'], 422);
        }

        $clinic->update([
            'password' => $validated['password'],
            'password_reset_token' => null,
            'password_reset_expires_at' => null,
        ]);

        // Revoke any existing tokens after password reset
        $clinic->tokens()->delete();

        return response()->json([
            'message' => 'Лозинката е успешно променета. Можете да се најавите.',
        ]);
    }

    private function publicClinic(ShopClinic $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'slug' => $c->slug,
            'edb' => $c->edb,
            'contact_person' => $c->contact_person,
            'email' => $c->email,
            'phone' => $c->phone,
            'city' => $c->city,
            'address' => $c->address,
            'description' => $c->description,
            'logo' => $c->logo,
            'status' => $c->status,
            'wallet_balance' => (float) $c->wallet_balance,
            'last_login_at' => $c->last_login_at,
        ];
    }
}
