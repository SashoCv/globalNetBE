<?php

namespace Database\Seeders;

use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\Event;
use App\Models\EventSession;
use Illuminate\Database\Seeder;

/**
 * Seeds the two interprofessional-collaboration research questionnaires:
 *
 *   • RIPLS  — Readiness for Interprofessional Learning Scale (healthcare students)
 *   • ICCAS  — Interprofessional Collaborative Competencies (healthcare professionals)
 *
 * Both are created under one event ("Interprofessional Education & Collaboration
 * Research"), one session each, and are switched on (is_active = true) so the
 * public links work immediately.
 *
 * Re-runnable: the event, sessions and evaluations are matched by name/title, so
 * running the seeder again updates them in place and keeps the existing QR tokens
 * (the public links never change). Questions are rebuilt on every run — EXCEPT for
 * an evaluation that already has responses, which is left untouched so collected
 * answers are never destroyed.
 *
 *   php artisan db:seed --class=IpeSurveysSeeder
 */
class IpeSurveysSeeder extends Seeder
{
    private const EVENT_NAME = 'Interprofessional Education & Collaboration Research';

    private const SCALE_NOTE = 'Response scale: 1 = Strongly disagree · 2 = Disagree · 3 = Neutral · 4 = Agree · 5 = Strongly agree.';

    public function run(): void
    {
        $event = Event::firstOrCreate(
            ['name' => self::EVENT_NAME],
            [
                'description' => '„Learning together today enables working together tomorrow" — anonymous research questionnaires on interprofessional education and collaboration.',
                'start_date' => now()->toDateString(),
                'location' => 'Online',
                'status' => 'active',
            ],
        );

        $this->seedStudents($event);
        $this->seedProfessionals($event);

        $this->command->newLine();
        $this->command->info('Готово. Линковите се активни веднаш — види ги во Админ → Настани → Евалуации.');
    }

    // ─────────────────────────────────────────────────────────────
    // RIPLS — students
    // ─────────────────────────────────────────────────────────────
    private function seedStudents(Event $event): void
    {
        $session = $this->session($event, [
            'name' => 'RIPLS — Healthcare Students',
            'description' => 'Readiness for Interprofessional Learning Scale — questionnaire for healthcare students.',
            'sort_order' => 0,
        ]);

        $description = implode("\n\n", [
            '"Learning together today enables working together tomorrow."',
            'Interprofessional education is increasingly recognized as a fundamental component of preparing future healthcare professionals for collaborative practice. Learning with, from, and about students from different healthcare disciplines promotes mutual understanding, effective communication, teamwork, and respect for professional roles. These competencies are essential for delivering high-quality, patient-centered care in modern healthcare systems.',
            'This anonymous questionnaire aims to explore healthcare students\' perceptions, experiences, and attitudes regarding interprofessional education and collaboration among healthcare professions.',
            'The collected data will be used for scientific and educational purposes and may contribute to future initiatives aimed at strengthening interprofessional learning and collaborative practice within healthcare education.',
            'Participation is voluntary, and all responses will remain confidential. Please do not write your name or any other information that could directly identify you.',
            'Instructions: Please select the response that best reflects your opinion or experience. Unless otherwise stated, select one answer only. ' . self::SCALE_NOTE,
            'Thank you for your time and valuable contribution.',
        ]);

        $evaluation = $this->evaluation($session, [
            'title' => 'RIPLS Questionnaire for Healthcare Students',
            'description' => $description,
        ]);

        if (! $this->canRebuildQuestions($evaluation)) {
            return;
        }

        $agreement = ['scale' => 5];

        $questions = [
            // ── Section A. Demographic Characteristics ──
            ['A1. Age (in years)', 'text', null, true],
            ['A2. Gender', 'radio', ['Male', 'Female', 'Other', 'Prefer not to answer'], true],
            ['A3. Country', 'text', null, true],
            ['A4. Study programme', 'radio', ['Medicine', 'Pharmacy', 'Dentistry', 'Dietetics and Dietotherapy', 'Physiotherapy', 'Other'], true],
            ['A4a. If you selected "Other", please specify your study programme', 'text', null, false],
            ['A5. Year of study', 'text', null, true],
            ['A6. Clinical practice experience (hospital, community pharmacy, primary care, or other healthcare setting)', 'radio', ['None', 'Less than 3 months', '3–6 months', '6–12 months', 'More than 12 months'], true],

            // ── Section B. Previous Interprofessional Learning and Collaboration Experience ──
            ['B1. How often do you collaborate with students from other healthcare disciplines?', 'radio', ['Never', 'Rarely', 'Sometimes', 'Often', 'Very often'], true],
            ['B2. During your study, have you had the opportunity to learn with students from other healthcare professions?', 'radio', ['Yes', 'No', 'Not sure', 'Not applicable'], true],
            ['B3. During your study, have you had the opportunity to learn with professionals from other healthcare professions?', 'radio', ['Yes', 'No', 'Not sure', 'Not applicable'], true],
            ['B4. Have you ever participated in interprofessional education (IPE)?', 'radio', ['Yes', 'No', 'Not sure'], true],
            ['B5. If yes, what type of previous IPE experience have you had? (Select all that apply.)', 'checkbox', ['Joint lectures/seminars', 'Workshops/simulation', 'Clinical activities', 'Other'], false],
            ['B5a. If you selected "Other", please specify the type of IPE experience', 'text', null, false],
            ['B5b. When did this take place and/or how long did it last?', 'text', null, false],

            // ── Section C. Readiness for Interprofessional Learning ──
            ['C1. Learning with other students will help me become a more effective member of a healthcare team.', 'rating', $agreement, true],
            ['C2. Patients would benefit if healthcare students worked together.', 'rating', $agreement, true],
            ['C3. Shared learning increases my ability to understand clinical problems.', 'rating', $agreement, true],
            ['C4. Learning with other students improves communication skills.', 'rating', $agreement, true],
            ['C5. Team-working skills are essential for all healthcare professionals.', 'rating', $agreement, true],
            ['C6. Shared learning helps me understand my professional limitations.', 'rating', $agreement, true],
            ['C7. For small-group learning to work, students need to trust and respect each other.', 'rating', $agreement, true],
            ['C8. Shared learning prepares me for teamwork after graduation.', 'rating', $agreement, true],
            ['C9. I would welcome the opportunity to work on projects with students from other professions.', 'rating', $agreement, true],
            ['C10. Communication skills should be learned with students from other professions.', 'rating', $agreement, true],
            ['C11. Shared learning will help clarify patient problems.', 'rating', $agreement, true],
            ['C12. Shared learning before qualification improves collaboration after qualification.', 'rating', $agreement, true],
            ['C13. Clinical problem-solving can be improved through interprofessional learning.', 'rating', $agreement, true],
            ['C14. Interprofessional learning helps me become a better team worker.', 'rating', $agreement, true],
            ['C15. I need to acquire more knowledge about the roles of other healthcare professionals.', 'rating', $agreement, true],
            ['C16. I am not sure what my professional role will be.', 'rating', $agreement, true],
            ['C17. I have to acquire much more knowledge and skills than other healthcare students.', 'rating', $agreement, true],
            ['C18. Shared learning with other healthcare students will help me communicate better with patients.', 'rating', $agreement, true],
            ['C19. Interprofessional learning improves future professional relationships.', 'rating', $agreement, true],
            ['C20. In your opinion, should interprofessional education be incorporated into undergraduate healthcare curricula in North Macedonia?', 'radio', ['Yes', 'No', 'Not sure'], true],

            // ── Section D. Open-ended Questions ──
            ['D1. What do you consider to be the main barriers to interprofessional collaboration?', 'textarea', null, false],
            ['D2. How could interprofessional education be improved in undergraduate healthcare programmes?', 'textarea', null, false],
        ];

        $this->writeQuestions($evaluation, $questions);
    }

    // ─────────────────────────────────────────────────────────────
    // ICCAS — professionals
    // ─────────────────────────────────────────────────────────────
    private function seedProfessionals(Event $event): void
    {
        $session = $this->session($event, [
            'name' => 'ICCAS — Healthcare Professionals',
            'description' => 'Interprofessional Collaborative Competencies — questionnaire for healthcare professionals.',
            'sort_order' => 1,
        ]);

        $description = implode("\n\n", [
            '"Learning together today enables working together tomorrow."',
            'Interprofessional collaboration is increasingly recognized as a cornerstone of high-quality, patient-centered healthcare. Effective teamwork among healthcare professionals contributes to improved patient outcomes, enhanced patient safety, more efficient use of healthcare resources, and greater professional satisfaction. As healthcare systems become more complex, collaboration between different professions is essential for addressing patients\' needs comprehensively and delivering optimal care.',
            'This anonymous questionnaire aims to explore healthcare professionals\' perceptions, experiences, and attitudes regarding interprofessional collaboration in clinical practice.',
            'The collected data will be used for scientific and educational purposes and may contribute to future initiatives aimed at strengthening collaborative practice within the healthcare system.',
            'Participation is voluntary, and all responses will remain confidential and will be analyzed in aggregate form.',
            'Instructions: Please select the response that best reflects your opinion or experience. Unless otherwise stated, select one answer only. ' . self::SCALE_NOTE,
            'Thank you for your time and valuable contribution.',
        ]);

        $evaluation = $this->evaluation($session, [
            'title' => 'ICCAS Questionnaire for Healthcare Professionals',
            'description' => $description,
        ]);

        if (! $this->canRebuildQuestions($evaluation)) {
            return;
        }

        $agreement = ['scale' => 5];

        $questions = [
            // ── Section A. Demographic and Professional Information ──
            ['A1. Age', 'radio', ['≤25 years', '26–35 years', '36–45 years', '46–55 years', '≥56 years'], true],
            ['A2. Gender', 'radio', ['Female', 'Male', 'Prefer not to say'], true],
            ['A3. Profession', 'radio', ['Pharmacist', 'Physician', 'Nurse', 'Dentist', 'Dietitian/Nutrition professional', 'Physiotherapist', 'Laboratory professional', 'Other'], true],
            ['A3a. If you selected "Other", please specify your profession', 'text', null, false],
            ['A4. Highest level of education', 'radio', ['Bachelor\'s degree', 'Master\'s degree', 'Specialist degree', 'PhD', 'Other'], true],
            ['A4a. If you selected "Other", please specify your highest level of education', 'text', null, false],
            ['A5. Years of professional experience', 'radio', ['<5 years', '5–10 years', '11–20 years', '≥21 years'], true],
            ['A6. Primary workplace', 'radio', ['Community pharmacy', 'Hospital', 'Primary healthcare center', 'University/academic institution', 'Private practice', 'Other'], true],
            ['A6a. If you selected "Other", please specify your primary workplace', 'text', null, false],
            ['A7. Do you regularly work in a multidisciplinary/interprofessional team?', 'radio', ['Yes', 'No'], true],
            ['A8. Have you previously participated in interprofessional education (IPE) activities?', 'radio', ['Yes', 'No'], true],
            ['A9. If yes, what type of previous IPE experience have you had? (Select all that apply.)', 'checkbox', ['Joint lectures/seminars', 'Workshops/simulation', 'Clinical activities', 'Other'], false],
            ['A9a. If you selected "Other", please specify the type of IPE experience', 'text', null, false],
            ['A9b. When/how long ago did this take place?', 'text', null, false],
            ['A10. Have you attended any training related to teamwork, communication, or collaborative practice during the last 5 years?', 'radio', ['Yes', 'No'], true],
            ['A11. How often do you collaborate with professionals from other healthcare disciplines?', 'radio', ['Daily', 'Weekly', 'Monthly', 'Rarely', 'Never'], true],
            ['A12. Country of current professional practice', 'text', null, true],

            // ── Section B. Interprofessional Collaboration ──
            ['B1. (Communication) I actively listen to the perspectives of other healthcare professionals.', 'rating', $agreement, true],
            ['B2. (Communication) I communicate effectively with members of an interprofessional team.', 'rating', $agreement, true],
            ['B3. (Communication) I adapt my communication style to different professional groups.', 'rating', $agreement, true],
            ['B4. (Roles and Responsibilities) I understand the roles and responsibilities of other healthcare professions.', 'rating', $agreement, true],
            ['B5. (Roles and Responsibilities) I respect the expertise and contributions of other healthcare professionals.', 'rating', $agreement, true],
            ['B6. (Roles and Responsibilities) I integrate the knowledge and expertise of other professions into patient care.', 'rating', $agreement, true],
            ['B7. (Teamwork and Collaboration) I work effectively within interprofessional teams.', 'rating', $agreement, true],
            ['B8. (Teamwork and Collaboration) I participate in shared decision-making with team members.', 'rating', $agreement, true],
            ['B9. (Teamwork and Collaboration) I contribute actively to team discussions and collaborative care planning.', 'rating', $agreement, true],
            ['B10. (Teamwork and Collaboration) I share accountability for patient outcomes.', 'rating', $agreement, true],
            ['B11. (Teamwork and Collaboration) I demonstrate leadership when appropriate.', 'rating', $agreement, true],
            ['B12. (Patient-Centred Care and Safety) I involve patients and their families in decisions regarding treatment and care.', 'rating', $agreement, true],
            ['B13. (Patient-Centred Care and Safety) When developing treatment plans, I consult colleagues from other professions.', 'rating', $agreement, true],
            ['B14. (Patient-Centred Care and Safety) I promote patient safety through effective teamwork.', 'rating', $agreement, true],
            ['B15. (Conflict Management and Professional Development) When disagreements arise, I seek to understand the perspectives of others.', 'rating', $agreement, true],
            ['B16. (Conflict Management and Professional Development) I use collaborative approaches to solve problems and resolve conflicts.', 'rating', $agreement, true],
            ['B17. (Conflict Management and Professional Development) I reflect on my own professional limitations.', 'rating', $agreement, true],
            ['B18. (Conflict Management and Professional Development) I seek and accept feedback from team members.', 'rating', $agreement, true],
            ['B19. (Team Climate) I trust the decisions made by the interprofessional team.', 'rating', $agreement, true],
            ['B20. (Team Climate) I value interprofessional collaboration as an essential component of healthcare practice.', 'rating', $agreement, true],
            ['B21. In your opinion, should interprofessional education be incorporated into undergraduate healthcare curricula in North Macedonia?', 'radio', ['Yes', 'No', 'Not sure'], true],

            // ── Section C. Open-ended Questions ──
            ['C1. What are the main barriers to interprofessional collaboration in your professional practice?', 'textarea', null, false],
            ['C2. How could interprofessional education and collaborative practice be improved?', 'textarea', null, false],
        ];

        $this->writeQuestions($evaluation, $questions);
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────
    private function session(Event $event, array $attributes): EventSession
    {
        return EventSession::updateOrCreate(
            ['event_id' => $event->id, 'name' => $attributes['name']],
            [
                'description' => $attributes['description'],
                'sort_order' => $attributes['sort_order'],
                'is_active' => true,
            ],
        );
    }

    private function evaluation(EventSession $session, array $attributes): Evaluation
    {
        return Evaluation::updateOrCreate(
            ['event_session_id' => $session->id, 'title' => $attributes['title']],
            [
                'description' => $attributes['description'],
                // Fully anonymous: the form asks for no name or e-mail.
                'anonymity_mode' => 'anonymous',
                'is_active' => true,
                'sort_order' => 0,
            ],
        );
    }

    /**
     * Never throw away answers that have already been collected.
     */
    private function canRebuildQuestions(Evaluation $evaluation): bool
    {
        $responses = $evaluation->responses()->count();

        if ($responses > 0) {
            $this->command->warn(
                "„{$evaluation->title}“ — веќе има {$responses} одговори, прашањата се оставени непроменети."
            );

            return false;
        }

        return true;
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: array|null, 3: bool}>  $questions
     */
    private function writeQuestions(Evaluation $evaluation, array $questions): void
    {
        $evaluation->questions()->delete();

        foreach ($questions as $i => [$text, $type, $options, $required]) {
            EvaluationQuestion::create([
                'evaluation_id' => $evaluation->id,
                'question_text' => $text,
                'type' => $type,
                'options' => $options,
                'required' => $required,
                'sort_order' => $i,
            ]);
        }

        $url = rtrim(config('app.frontend_url') ?: 'https://www.globalnetadv.mk', '/') . '/evaluation/' . $evaluation->qr_token;

        $this->command->info("„{$evaluation->title}“ — " . count($questions) . ' прашања · активна');
        $this->command->line("   {$url}");
    }
}
