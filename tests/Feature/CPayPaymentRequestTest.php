<?php

namespace Tests\Feature;

use App\Models\ShopClinic;
use App\Models\ShopOrder;
use App\Services\CPay\CPayChecksum;
use App\Services\CPay\CPayPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CPayPaymentRequestTest extends TestCase
{
    use RefreshDatabase;

    private ShopClinic $clinic;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cpay.checksum_key' => 'TEST_PASS',
            'cpay.checksum_algo' => 'sha256',
            'cpay.merchant_id' => '1234567890',
            'cpay.merchant_name' => 'GNAESHOP',
            'cpay.payment_url' => 'https://vpos.cpay.com.mk/mk-MK',
            'cpay.ok_url' => 'https://www.gnaeshop.mk/cpay/ok',
            'cpay.fail_url' => 'https://www.gnaeshop.mk/cpay/fail',
            'cpay.test_mode' => false,
            'cpay.test_amount_override' => null,
        ]);

        $this->clinic = ShopClinic::create([
            'name' => 'ПЗУ Тест',
            'slug' => 'pzu-test',
            'edb' => '4000000000000',
            'contact_person' => 'Д-р Иван Ивановски',
            'email' => 'klinika@example.com',
            'phone' => '070220070',
            'city' => 'Скопје',
            'address' => 'ул. Мексичка бр. 13Б-1',
            'status' => 'approved',
            'password' => bcrypt('secret'),
        ]);
    }

    public function test_the_request_carries_the_cardholder_details_for_3ds2(): void
    {
        $fields = $this->fieldsFor($this->order());

        // The title is not part of the name.
        $this->assertSame('Ivan', $fields['FirstName']);
        $this->assertSame('Ivanovski', $fields['LastName']);
        // Cyrillic is transliterated — cPay takes Latin characters only.
        $this->assertSame('Skopje', $fields['City']);
        $this->assertSame('807', $fields['Country']);
        $this->assertSame('ul Meksicka br 13B-1', $fields['Address']);
        $this->assertSame('38970220070', $fields['Telephone']);
    }

    public function test_an_orders_own_delivery_details_win_over_the_clinic_record(): void
    {
        $fields = $this->fieldsFor($this->order([
            'delivery_contact' => 'Марија Стојанова',
            'delivery_city' => 'Битола',
            'delivery_address' => 'ул. Партизанска 5',
            'delivery_phone' => '+389 71 555 444',
        ]));

        $this->assertSame('Marija', $fields['FirstName']);
        $this->assertSame('Stojanova', $fields['LastName']);
        $this->assertSame('Bitola', $fields['City']);
        $this->assertSame('ul Partizanska 5', $fields['Address']);
        $this->assertSame('38971555444', $fields['Telephone']);
    }

    /**
     * cPay requires the amount times 100 with the last two digits at 00.
     */
    public function test_the_amount_is_sent_in_whole_denars(): void
    {
        $fields = $this->fieldsFor($this->order(['total' => 16495.25]));

        $this->assertSame('1649500', $fields['AmountToPay']);
    }

    public function test_the_checksum_covers_every_parameter_that_is_sent(): void
    {
        $fields = $this->fieldsFor($this->order());

        $checksum = new CPayChecksum('TEST_PASS', 'sha256');

        $signed = $checksum->orderRequest($fields);

        // isSimple is the only field that stays out of the checksum.
        $this->assertSame(
            ['isSimple', 'CheckSumHeader', 'CheckSum'],
            array_values(array_diff(array_keys($fields), array_keys($signed))),
        );

        $this->assertSame($fields['CheckSumHeader'], $checksum->header($signed));
        $this->assertSame($fields['CheckSum'], $checksum->checksum($signed));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function order(array $attributes = []): ShopOrder
    {
        return ShopOrder::create(array_merge([
            'order_number' => 'GNA-2026-00013',
            'shop_clinic_id' => $this->clinic->id,
            'status' => 'new',
            'payment_status' => 'pending',
            'subtotal' => 1000,
            'total' => 1000,
            'placed_at' => now(),
        ], $attributes));
    }

    /**
     * @return array<string, string>
     */
    private function fieldsFor(ShopOrder $order): array
    {
        $service = app(CPayPaymentService::class);

        return $service->redirectForm($service->start($order, $this->clinic))['fields'];
    }
}
