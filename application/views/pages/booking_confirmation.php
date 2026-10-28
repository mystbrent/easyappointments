<?php extend('layouts/message_layout'); ?>

<?php section('content'); ?>

<div>
    <svg id="success-icon" class="mt-0 mb-5" width="80" height="80" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="success">
        <circle cx="12" cy="12" r="10"/>
        <path d="M7.5 12.5l3 3 6-6.5"/>
    </svg>
</div>

<div class="mb-5">
    <h4 class="mb-5"><?= lang('appointment_registered') ?></h4>

    <?php if (setting('customer_notifications')): ?>
        <p>
            <?= lang('appointment_details_was_sent_to_you') ?>
        </p>
    <?php endif; ?>

    <p class="mb-5 text-muted">
        <small>
            <?= lang('check_spam_folder') ?>
        </small>
    </p>

    <a href="<?= site_url() ?>" class="btn btn-primary btn-large">
        <i class="fas fa-calendar-alt me-2"></i>
        <?= lang('go_to_booking_page') ?>
    </a>

    <a href="<?= vars('add_to_google_url') ?>" id="add-to-google-calendar" class="btn btn-primary" target="_blank">
        <i class="fas fa-plus me-2"></i>
        <?= lang('add_to_google_calendar') ?>
    </a>
</div>

<?php end_section('content'); ?>

<?php section('scripts'); ?>

<?php component('google_analytics_script', ['google_analytics_code' => vars('google_analytics_code')]); ?>
<?php component('matomo_analytics_script', [
    'matomo_analytics_url' => vars('matomo_analytics_url'),
    'matomo_analytics_site_id' => vars('matomo_analytics_site_id'),
]); ?>

<?php end_section('scripts'); ?>
