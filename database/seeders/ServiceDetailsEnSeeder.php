<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

/**
 * ServiceDetailsEnSeeder — translates the rich references content inside
 * services.details_en (events listing + training reports). Runs AFTER
 * ServiceEnSeeder, which creates details_en as a copy of the Macedonian data
 * with translated headings; this seeder fills in the English text for the
 * event titles/participants and the full training items.
 *
 * Client/venue values that are proper nouns (organizations, school names,
 * company list for "clients") are left as-is. Idempotent — matches by year and
 * item index, so it is safe to re-run.
 */
class ServiceDetailsEnSeeder extends Seeder
{
    public function run(): void
    {
        $this->translateEvents();
        $this->translateTraining();
    }

    private function translateEvents(): void
    {
        // year => [ index => [title, participants] ]
        $en = [
            2025 => [
                ['Second Symposium — New Medicine Service Course', '300 (N. Macedonia)'],
                ['Congress with international participation', '165 (N. Macedonia, Serbia, Turkey, Slovenia, Kosovo)'],
                ['12th Congress with international participation', '185 (N. Macedonia, Serbia, Croatia, Slovenia, Romania, Portugal)'],
                ['UEMO General Assembly', '55 (24 countries — N. Macedonia, Serbia, Slovenia, Austria, Germany, Belgium, Greece, Czechia, Finland, France, Georgia, Italy, Ireland, Kosovo, Luxembourg, Malta, Lithuania, Norway, Portugal, Romania, Spain, Sweden, Slovakia, UK)'],
                ['Symposium with international participation', '180 (N. Macedonia, Serbia)'],
                ['Summer school for postdoctoral researchers', '45 (New Zealand, India, China, Tunisia, Costa Rica, USA, Ireland, Germany, Austria, Italy, Spain, Portugal, Russia, Netherlands, England)'],
                ['International Symposium Rhythm 2025', '290 (N. Macedonia, Serbia, Kosovo)'],
                ['6th Congress with international participation', '505 (N. Macedonia, Bulgaria, Serbia, BiH)'],
                ['International Symposium', '260 (N. Macedonia)'],
                ['First Symposium and 4th Meeting of the WEB Chapter of E-AHPBA', '170 (N. Macedonia, Serbia, Slovenia, Croatia)'],
                ['Celebration of 80 years of the Official Gazette', ''],
            ],
            2024 => [
                ['Expert meeting — Challenges in modern urology', '100 (N. Macedonia)'],
                ['Symposium with international participation', '110 (N. Macedonia)'],
                ['Symposium — Development of pharmacy practice and interprofessional collaboration', '210 (N. Macedonia)'],
                ['6th Congress with international participation', '140 (N. Macedonia, Serbia)'],
                ['7th Congress with international participation', '180 (N. Macedonia)'],
                ['First Symposium and EAGEN postgraduate course', '150 (N. Macedonia)'],
                ['International tournament', '270 (N. Macedonia, Turkey, Bulgaria, Romania, Greece)'],
                ['Annual Assembly of the General European OMCL Network (GEON) 2024', '250 (Europe, Asia, N. America)'],
                ['International symposium — Allergies in childhood', '160 (N. Macedonia)'],
                ['Symposium — Innovations in ophthalmology', '140 (N. Macedonia)'],
                ['2nd Symposium — Pharmaceutical Health Care', '150 (N. Macedonia)'],
                ['2nd Vessels Symposium', '120 (N. Macedonia)'],
                ['International symposium — Glaucoma as a comorbidity', '100 (N. Macedonia)'],
            ],
            2023 => [
                ['Symposium — Neonatology Days', '120 (N. Macedonia)'],
                ['7th Congress with international participation', '450 (N. Macedonia, Serbia, BiH, Croatia, Slovenia, Albania, Kosovo)'],
                ['14th Central European Symposium on Pharmaceutical Technology', '250 (N. Macedonia, Serbia, BiH, Croatia, Slovenia, Albania, Kosovo, Poland, Turkey, Germany…)'],
                ['6th Symposium and First Symposium — Innovations in pulmonology', '250 (N. Macedonia, Serbia, Montenegro)'],
                ['Third Symposium and First International Congress of the Neurology Section', '200 (N. Macedonia, Serbia, Kosovo)'],
                ['11th Congress with international participation', '280 (N. Macedonia, Serbia, Kosovo, Croatia)'],
                ['5th Congress and 25th Symposium with international participation', '450 (N. Macedonia, Serbia, Slovenia, Kosovo, Croatia, Turkey)'],
                ['Symposium with international participation', '200 (N. Macedonia, Serbia, Kosovo)'],
                ['3rd International Symposium on Thrombosis', '130 (N. Macedonia, Serbia, Kosovo)'],
            ],
            2022 => [
                ['First joint symposium with international participation', '200 (N. Macedonia, Serbia, Croatia, Kosovo)'],
                ['6th Congress with international participation', '180 (N. Macedonia, Serbia, Slovenia, Croatia, Kosovo)'],
                ['Symposium with international participation', '120 (lecturers from UK, Serbia, Italy, Georgia, Turkey, USA, Poland, Croatia, Bulgaria, UAE)'],
                ['Symposium with international participation', '130 (N. Macedonia)'],
                ['2nd Congress with international participation', '200 (N. Macedonia, Serbia, Kosovo)'],
                ['5th Congress with international participation', '200 (N. Macedonia, Serbia, Kosovo)'],
                ['12th Balkan Congress of Otorhinolaryngology; First joint meeting of the American Academy of ORL and the European Confederation of ORL', '400 (11 countries — including N. Macedonia, Serbia, Bulgaria, Croatia, BiH, Kosovo, Albania, Montenegro, Slovenia, Turkey)'],
                ['First Macedonian Congress of Internal Medicine', '350 (N. Macedonia, Serbia, Kosovo)'],
                ['Second Symposium — Innovations in neurology', '180'],
                ['Symposium — Modern surgery, challenge and need', '180 (N. Macedonia, Serbia, Kosovo)'],
            ],
            2021 => [
                ['First symposium on anesthesiology, resuscitation and intensive care', '220'],
                ['First symposium — Innovations in neurology', '170'],
                ['7th Congress with international participation', '250'],
            ],
            2019 => [
                ['6th Congress with international participation', '190'],
                ['6th Congress with international participation', '550 (60 lecturers from USA, Germany, Italy, UK, Russia, France, Slovenia, Croatia, Romania, Turkey, Bulgaria, Greece, Serbia, BiH, Macedonia)'],
                ['15th BANTAO Congress and 6th Congress with international participation', '350 (50 lecturers from USA, Japan, Germany, Italy, Australia, Czechia, Belgium, Slovenia, etc.)'],
                ['3rd Symposium with international participation', '200'],
                ['First Balkan Congress of Urology', '250 (N. Macedonia, Serbia, Bulgaria, Kosovo)'],
            ],
            2018 => [
                ['6th Congress with international participation', '300'],
                ['4th Congress with international participation', '400'],
            ],
        ];

        $svc = Service::where('sort_order', 0)->first();
        if (! $svc || ! is_array($svc->details_en) || empty($svc->details_en['events'])) {
            return;
        }

        $d = $svc->details_en;
        foreach ($d['events'] as &$yblock) {
            $year = (int) ($yblock['year'] ?? 0);
            $map = $en[$year] ?? null;
            if (! $map || empty($yblock['items'])) {
                continue;
            }
            foreach ($yblock['items'] as $i => &$item) {
                if (! isset($map[$i])) {
                    continue;
                }
                [$title, $participants] = $map[$i];
                $item['title'] = $title;
                $item['participants'] = $participants;
                if (($item['venue'] ?? '') === 'Онлајн') {
                    $item['venue'] = 'Online';
                }
            }
            unset($item);
        }
        unset($yblock);

        $svc->details_en = $d;
        $svc->save();
    }

    private function translateTraining(): void
    {
        $en = [
            [
                'title' => 'Strengthening the skills of professional associates for counseling on professional and career orientation of students',
                'client' => 'Bureau for Development of Education',
                'date' => 'October – November 2025',
                'paragraphs' => [
                    'During October and November 2025, trainings were carried out as part of the professional development of professional associates and career counselors in secondary education in the Republic of Macedonia.',
                    'Global Net Adv, as a provider of professional development services for professional associates and career counselors in secondary schools on the topic of strengthening the skills of professional associates for counseling on professional and career orientation of students, was accredited by the Bureau for Development of Education.',
                    'In line with the accreditation decision and contract, during the stated period and the previously set training schedule Global Net Adv successfully delivered a total of 20 trainings across the Republic of Macedonia, training 486 professional associates and career counselors from secondary education.',
                ],
                'goalLabel' => '',
                'goalItems' => [],
                'extraParagraphs' => [],
                'closing' => '',
                'participants' => '486 professional associates and career counselors',
            ],
            [
                'title' => 'Integration of artificial intelligence into modern educational software',
                'paragraphs' => [
                    'On 06.12.2024, on the premises of SOU Mavrovo i Rostuše, a training was organized for 42 teachers from the school on the topic of integrating artificial intelligence into modern educational software, organized by the marketing agency Global Net ADV and delivered by Dr. Fehmi Skender, a top expert on the subject.',
                ],
                'goalLabel' => 'The goal of the training was:',
                'goalItems' => [
                    'The challenges of using AI in teaching,',
                    'The dangers of using AI,',
                    'AI tools in service of lesson preparation,',
                    'Using various AI tools in delivering the class.',
                ],
                'extraParagraphs' => [],
                'closing' => 'Throughout the training there was a pleasant atmosphere; all participants actively took part in the workshops, discovering and embracing the challenge of applying various AI tools for teaching needs, aiming for the deepest and most lasting knowledge among students.',
                'participants' => '42 teachers',
            ],
            [
                'title' => 'Improving the competencies of school board members to increase their efficiency and effectiveness at work',
                'paragraphs' => [
                    'Due to the large number of registered participants from different places of residence and work, an online training for school board members was held on 09.10.2024 on the topic of improving the competencies of school board members to increase their efficiency and effectiveness at work.',
                ],
                'goalLabel' => 'The goal of the training was:',
                'goalItems' => [
                    'To familiarize school board members with all legal provisions for participating in this body, the highest governing body of the school,',
                    'To interactively solve practical case studies frequently encountered in school practice,',
                    'To improve their competencies, thereby contributing to the work of the school in general and indirectly improving the quality of teaching within it.',
                ],
                'extraParagraphs' => [],
                'closing' => 'The training was delivered by Dr. Makedonka Vilarova, municipal inspector of the Municipality of Veles, an associate of the agency and a top expert on this subject. Although the training, attended by 39 school board members, was held online, there was no lack of interaction between the trainer and the attendees. Throughout the training the attendees actively participated, sharing positive examples and seeking common solutions to pressing problems in schools.',
                'participants' => '39 school board members',
            ],
            [
                'title' => 'Formative and summative assessment in teaching',
                'paragraphs' => [
                    'At OU Kole Kaninski — Bitola, on 25.09.2024, a training was held on the topic of formative and summative assessment in teaching for newly employed subject and class teachers, as well as for all teachers who felt they needed to refresh the knowledge required for their work. The training was attended by 72 teachers and 3 professional associates and was delivered by Mimoza Trajkovska, a computer science teacher and teacher-mentor, regional trainer on this topic.',
                ],
                'goalLabel' => 'The goals of the training were:',
                'goalItems' => [
                    'enabling teachers to apply formative and summative assessment and to use different assessment methods,',
                    'enabling higher-quality assessment while respecting the basic principles of assessment, and',
                    'creating appropriate concepts for applying higher-quality assessment.',
                ],
                'extraParagraphs' => [
                    'In the first part of the training, participants were reminded of the mistakes repeated daily in assessment, as well as of the principles of assessment.',
                    'In the second part, participants were introduced to assessment methods and how each can be applied in formative and summative assessment. At the end, the topic of creating the summative annual grade as a result of thematic assessment was briefly addressed.',
                    'The training was conducted in a pleasant, working atmosphere. The teachers interest, both in their number (attendance) and in their work on the exercises and the discussions held, was at an enviable level.',
                ],
                'closing' => '',
                'participants' => '72 teachers and 3 professional associates',
            ],
            [
                'title' => 'Basic principles of organ transplantation and organ donation',
                'paragraphs' => [
                    'Basic principles of organ transplantation and organ donation — a training for doctors, held online.',
                    'The lectures were delivered by the National Transplantation Coordinator and the Deputy National Transplantation Coordinator, as well as specialist doctors from the Clinic for Nephrology and Cardiology. The training was attended by 252 doctors.',
                ],
                'goalLabel' => '',
                'goalItems' => [],
                'extraParagraphs' => [],
                'closing' => '',
                'participants' => '252 doctors',
            ],
            [
                'title' => 'Training for journalists — Transplantation and organ donation',
                'paragraphs' => [
                    'A training for journalists designed to improve knowledge in the field of transplantation and organ donation, as part of the Project for improving the capacities of the National Transplantation System implemented by the Ministry of Health and the EU Delegation in Skopje.',
                    'This training aimed to inform journalists about the organization of the entire transplantation system in the country, about the legal, professional and ethical dilemmas we face, and about the media impact that transplantation has in the public through the work of journalists.',
                    'The interactive sessions were led by representatives of the Ministry of Health, the National Transplantation Coordinator and the Deputy National Transplantation Coordinator, the Head of the Transplantation Program, and the PR service of the Ministry of Health. 22 journalists attended.',
                ],
                'goalLabel' => '',
                'goalItems' => [],
                'extraParagraphs' => [],
                'closing' => '',
                'participants' => '22 journalists',
            ],
        ];

        $svc = Service::where('sort_order', 1)->first();
        if (! $svc || ! is_array($svc->details_en) || empty($svc->details_en['training'])) {
            return;
        }

        $d = $svc->details_en;
        foreach ($d['training'] as $i => &$t) {
            $tr = $en[$i] ?? null;
            if (! $tr) {
                continue;
            }
            $t['title'] = $tr['title'];
            $t['paragraphs'] = $tr['paragraphs'];
            $t['goalLabel'] = $tr['goalLabel'];
            $t['goalItems'] = $tr['goalItems'];
            $t['extraParagraphs'] = $tr['extraParagraphs'];
            $t['closing'] = $tr['closing'];
            $t['participants'] = $tr['participants'];
            if (isset($tr['client'])) {
                $t['client'] = $tr['client'];
            }
            if (isset($tr['date'])) {
                $t['date'] = $tr['date'];
            }
            if (($t['venue'] ?? '') === 'Онлајн') {
                $t['venue'] = 'Online';
            }
        }
        unset($t);

        $svc->details_en = $d;
        $svc->save();
    }
}
