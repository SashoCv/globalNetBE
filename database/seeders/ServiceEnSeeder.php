<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

/**
 * ServiceEnSeeder — English translations for the services shown on the public
 * "/services" pages. Macedonian stays the base (name/description/bullets/details);
 * this fills the *_en columns. Idempotent: matches existing services by
 * sort_order and bullets by their order, so it is safe to re-run.
 *
 * The `details` references section (events/training/clients) is mostly proper
 * nouns + images, so details_en copies the Macedonian data and only translates
 * the section headings. Admins can refine any of this from /admin/services.
 */
class ServiceEnSeeder extends Seeder
{
    public function run(): void
    {
        // Keyed by sort_order.
        $data = [
            0 => [
                'name' => 'Event Organization',
                'description' => 'We offer a comprehensive and professional event organization service, from initial concept to complete execution. Whether it is conferences, congresses, seminars, workshops, team building or corporate and promotional events, we provide full logistical, technical and creative support.',
                'bullets' => [
                    'Conceptual planning – Defining event goals, choosing the format (conferences, congresses, seminars, workshops, team building, corporate/promotional events)',
                    'Venue selection and coordination – Identifying suitable locations, managing bookings, space and infrastructure',
                    'Technical and audiovisual support – Sound, microphones, projection, LED screens, lighting and a technician on site',
                    'Branding and visual identity – Banners, event web design, printed materials, ID badges',
                    'Participant registration – Online/on-site registration, attendee lists, support',
                    'Speaker and sponsor coordination – Communication, logistics, program alignment',
                    'Catering – Coffee breaks, cocktails, working lunches, special requests',
                    'Protocol and operational management – Following the program, respecting the timeline',
                    'Financial and administrative support – Budgeting, supplier coordination, invoicing',
                    'Post-event support – Detailed reports, attendance analysis, evaluation',
                ],
            ],
            1 => [
                'name' => 'Training',
                'description' => 'We provide high-quality, professionally designed training programs that improve knowledge, skills and professional competencies. The programs are developed according to current standards and modern professional needs.',
                'bullets' => [
                    'Professional and thematic training in various fields',
                    'Experienced instructors with proven professional experience',
                    'A structured and systematic educational approach',
                    'Training available live, online or in a hybrid format',
                    'Continuous professional education',
                    'Corporate and institutional training',
                    'Management, organization and business communication',
                    'Specialized programs and workshops',
                ],
            ],
            2 => [
                'name' => 'Promotional Activities',
                'description' => 'We provide comprehensive promotional and marketing support across the entire territory of North Macedonia. We have a network of coordinators for efficient execution in all cities.',
                'bullets' => [
                    'Engaging promoters, hostesses, models and promotion agents',
                    'Design and implementation of campaigns for new and existing products',
                    'Organizing tastings and presentations in retail outlets',
                    'Distribution of promotional materials (flyers, brochures, catalogs, samples)',
                    'Direct marketing toward defined consumer segments',
                    'Support for fairs and business events',
                    'Logistical coordination including staff, transport and materials',
                    'Regional and national planning for sustainable market presence',
                ],
            ],
            3 => [
                'name' => 'Surveys & Research',
                'description' => 'We offer comprehensive survey and research services covering data collection, processing and field analysis using modern methodologies. Our team consists of more than 50 trained surveyors and researchers.',
                'bullets' => [
                    'Field research and survey activities, data collection and processing',
                    'Competition research and market assessment',
                    'Monitoring of organizational structures and evaluation of the sales network',
                    'Mystery shopping for controlling sales personnel',
                    'Research of sales channels and development of new markets',
                    'Measuring customer satisfaction and reporting',
                    'Market monitoring to prevent counterfeiting and illegal sales',
                ],
            ],
            4 => [
                'name' => 'Brand Creation & Development',
                'description' => 'Brand creation is a creative process that involves many important steps for defining and positioning brands on the market.',
                'bullets' => [
                    'Defining vision and mission – Establishing the brand goals and the value for customers',
                    'Target audience identification – Understanding the ideal buyers, their needs and characteristics',
                    'Market and competition analysis – Evaluating positioning and opportunities for differentiation',
                    'Creating unique value – Determining what makes the brand different',
                    'Visual identity development – Logo design, color schemes, fonts and consistent graphics',
                    'Communication voice and tone – Establishing a consistent brand message',
                    'Brand positioning – Deciding how customers should perceive the brand',
                    'Marketing strategy – Choosing channels to build awareness and engage the audience',
                ],
            ],
        ];

        // Macedonian heading -> English, for the details references section.
        $headings = [
            'Реализирани настани' => 'Completed events',
            'Избор од нашата архива на организирани конгреси, симпозиуми и конференции.' => 'A selection from our archive of organized congresses, symposiums and conferences.',
            'Реализирани обуки' => 'Completed trainings',
            'Избор од нашата програма на стручни и акредитирани обуки.' => 'A selection from our program of professional and accredited trainings.',
            'Нашите клиенти' => 'Our clients',
            'Компании и организации со кои соработувавме на промотивни активности.' => 'Companies and organizations we collaborated with on promotional activities.',
        ];

        foreach (Service::orderBy('sort_order')->get() as $svc) {
            $d = $data[$svc->sort_order] ?? null;
            if (! $d) {
                continue;
            }

            $svc->name_en = $d['name'];
            $svc->description_en = $d['description'];

            if (is_array($svc->details) && ! empty($svc->details)) {
                $de = $svc->details;
                foreach (['refsHeading', 'refsSubheading'] as $k) {
                    if (! empty($de[$k]) && isset($headings[$de[$k]])) {
                        $de[$k] = $headings[$de[$k]];
                    }
                }
                $svc->details_en = $de;
            }

            $svc->save();

            $bullets = $svc->bullets()->orderBy('sort_order')->get();
            foreach ($bullets->values() as $idx => $bullet) {
                if (isset($d['bullets'][$idx])) {
                    $bullet->text_en = $d['bullets'][$idx];
                    $bullet->save();
                }
            }
        }
    }
}
