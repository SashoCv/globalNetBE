<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * SettingsEnSeeder — English translations for the public "globalnet" content.
 *
 * Macedonian rows (locale='mk') are seeded by DatabaseSeeder and remain the base
 * locale. This seeder adds the matching English copy (locale='en'). It is
 * idempotent (updateOrCreate on key+locale), so it is safe to re-run on an
 * already-seeded database. Keys left out here simply fall back to Macedonian on
 * the public site.
 */
class SettingsEnSeeder extends Seeder
{
    public function run(): void
    {
        $en = [
            'site_description' => 'Marketing and advertising agency',
            'address' => '36a Bagdadska St, 2/8, Skopje',
            'working_hours' => 'Mon - Fri: 09:00 - 17:00',

            // Home — hero
            'hero_eyebrow' => 'Marketing & advertising agency',
            'hero_title' => 'For over 35 years we have created memorable events, successful promotions and recognizable brands.',
            'hero_subtitle' => 'From organizing conferences and corporate events, through promotional activities and market research, to creative brand development – we deliver solutions that bring results.',

            // Home — stats
            'stat_years_label' => 'Years of experience',
            'stat_projects_label' => 'Completed projects',
            'stat_surveyors_label' => 'Trained surveyors',
            'stat_clients_label' => 'Satisfied clients',

            // Home — services overview
            'home_services_label' => 'WHAT WE DO',
            'home_services_heading' => 'Complete marketing solutions',
            'home_services_subtitle' => 'From concept to execution, we offer comprehensive services tailored to your needs.',

            // Home — about
            'home_about_label' => 'ABOUT US',
            'home_about_heading' => 'Your partner for marketing solutions',
            'about_text' => 'Global Net ADV is a marketing and advertising agency based in Skopje. With more than 20 years of experience, we offer complete solutions for event organization, promotional activities, training, market research and brand creation.',
            'about_text_2' => 'Our team consists of more than 50 trained professionals who enable fast and precise execution of projects across the entire territory of North Macedonia.',

            // Home — CTA
            'home_cta_title' => 'Contact us and together we will create experiences that leave an impression.',
            'home_cta_subtitle' => 'Prepare your business for success with our professional marketing solutions.',

            // Home — gallery
            'home_gallery_heading' => 'From our gallery',

            // Services page
            'services_hero_title' => 'What We Do',
            'services_hero_subtitle' => 'From organizing conferences and corporate events, through promotional activities and market research, to creative brand development.',
            'services_cta_title' => 'Prepare your business for success',

            // Contact page
            'contact_hero_title' => 'Contact',
            'contact_hero_subtitle' => 'Contact us and together we will create experiences that leave an impression',
            'contact_form_name_label' => 'Full Name',
            'contact_form_name_placeholder' => 'Your full name',
            'contact_form_email_label' => 'Email',
            'contact_form_email_placeholder' => 'your@email.com',
            'contact_form_phone_label' => 'Phone',
            'contact_form_subject_label' => 'Subject',
            'contact_form_subject_placeholder' => 'Message subject',
            'contact_form_message_label' => 'Message',
            'contact_form_message_placeholder' => 'Write your message...',
            'contact_form_submit_label' => 'Send message',

            // Footer
            'footer_description' => 'A marketing and advertising agency with more than 30 years of experience in creating memorable events, successful promotions and recognizable brands.',

            // SEO
            'seo_description' => 'Global Net ADV - Marketing and advertising agency in Skopje. Event organization, training, promotional activities, market research and brand development.',
        ];

        foreach ($en as $key => $value) {
            Setting::updateOrCreate(
                ['key' => $key, 'locale' => 'en'],
                ['value' => $value, 'group' => 'globalnet']
            );
        }
    }
}
