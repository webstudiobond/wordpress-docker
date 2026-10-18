<?php

declare(strict_types=1);

namespace WordPressDocker\AdminControl\Config;

/**
 * Provides static CSS and XPath selector maps and default target associations for profile hardening.
 */
final class SelectorsMap
{
    /**
     * @var array<string, string>
     */
    public const array PROFILE_FIELD_SELECTORS_MAP = [
        'syntax_highlighting' => 'tr.user-syntax-highlighting-wrap',
        'syntax-highlighting' => 'tr.user-syntax-highlighting-wrap',
        'color_scheme' => 'tr.user-admin-color-wrap',
        'admin_color' => 'tr.user-admin-color-wrap',
        'admin-color' => 'tr.user-admin-color-wrap',
        'shortcuts' => 'tr.user-comment-shortcuts-wrap',
        'comment_shortcuts' => 'tr.user-comment-shortcuts-wrap',
        'comment-shortcuts' => 'tr.user-comment-shortcuts-wrap',
        'toolbar' => 'tr.user-admin-bar-front-wrap',
        'admin_bar' => 'tr.user-admin-bar-front-wrap',
        'admin-bar' => 'tr.user-admin-bar-front-wrap',
        'admin_bar_front' => 'tr.user-admin-bar-front-wrap',
        'admin-bar-front' => 'tr.user-admin-bar-front-wrap',
        'infinite_scrolling' => 'tr.user-infinite-scrolling-wrap',
        'infinite-scrolling' => 'tr.user-infinite-scrolling-wrap',
        'user_login' => 'tr.user-user-login-wrap, tr.user-login-wrap, input#user_login, label[for="user_login"]',
        'user-login' => 'tr.user-user-login-wrap, tr.user-login-wrap, input#user_login, label[for="user_login"]',
        'username' => 'tr.user-user-login-wrap, tr.user-login-wrap, input#user_login, label[for="user_login"]',
        'first_name' => 'tr.user-first-name-wrap, input#first_name',
        'first-name' => 'tr.user-first-name-wrap, input#first_name',
        'last_name' => 'tr.user-last-name-wrap, input#last_name',
        'last-name' => 'tr.user-last-name-wrap, input#last_name',
        'nickname' => 'tr.user-nickname-wrap, input#nickname',
        'display_name' => 'tr.user-display-name-wrap, select#display_name',
        'display-name' => 'tr.user-display-name-wrap, select#display_name',
        'website' => 'tr.user-url-wrap, input#url, label[for="url"]',
        'url' => 'tr.user-url-wrap, input#url, label[for="url"]',
        'description' => 'tr.user-description-wrap, tr[class*="user-description-"], '
            . 'textarea[name^="description"], label[for^="description"]',
        'bio' => 'tr.user-description-wrap, tr[class*="user-description-"], '
            . 'textarea[name^="description"], label[for^="description"]',
        'profile_picture' => 'tr.user-profile-picture-wrap, tr.user-profile-picture',
        'profile-picture' => 'tr.user-profile-picture-wrap, tr.user-profile-picture',
        'avatar' => 'tr.user-profile-picture-wrap, tr.user-profile-picture',
        'about' => 'tr.user-description-wrap, tr[class*="user-description-"], '
            . 'textarea[name^="description"], tr.user-profile-picture-wrap, tr.user-profile-picture',
        'about_yourself' => 'tr.user-description-wrap, tr[class*="user-description-"], '
            . 'textarea[name^="description"], tr.user-profile-picture-wrap, tr.user-profile-picture',
        'about-yourself' => 'tr.user-description-wrap, tr[class*="user-description-"], '
            . 'textarea[name^="description"], tr.user-profile-picture-wrap, tr.user-profile-picture',
        'rich_editing' => 'tr.user-rich-editing-wrap',
        'rich-editing' => 'tr.user-rich-editing-wrap',
        'visual_editor' => 'tr.user-rich-editing-wrap',
        'visual-editor' => 'tr.user-rich-editing-wrap',
        'language' => 'tr.user-language-wrap, select#locale',
        'locale' => 'tr.user-language-wrap, select#locale',
        'sessions' => 'tr.user-sessions-wrap',
        'application_passwords' => 'tr.user-application-passwords-wrap, div.application-passwords',
        'application-passwords' => 'tr.user-application-passwords-wrap, div.application-passwords',
        'elementor_ai' => 'tr.user-elementor_enable_ai-wrap, input[name*="elementor_enable_ai"]',
        'elementor-ai' => 'tr.user-elementor_enable_ai-wrap, input[name*="elementor_enable_ai"]',
        'elementor_enable_ai' => 'tr.user-elementor_enable_ai-wrap, input[name*="elementor_enable_ai"]',
        'elementor_notes' => 'tr.user-elementor_pro_enable_notes_notifications-wrap, input[name*="notes"]',
        'elementor-notes' => 'tr.user-elementor_pro_enable_notes_notifications-wrap, input[name*="notes"]',
        'elementor_pro_enable_notes_notifications' => 'tr.user-elementor_pro_enable_notes_notifications-wrap, '
            . 'input[name*="notes"]',
        'elementor' => 'tr[class*="elementor"], input[name*="elementor"], label[for*="elementor"]',
        'wordfence' => '#wfls-user-settings, tr#wfls-user-2fa-status, tr#wfls-user-passkey-status, '
            . 'tr.user-wordfence-ls-wrap, tr.user-wordfence-ls-passkey-wrap',
        'wfls' => '#wfls-user-settings, tr#wfls-user-2fa-status, tr#wfls-user-passkey-status, '
            . 'tr.user-wordfence-ls-wrap, tr.user-wordfence-ls-passkey-wrap',
        'wordfence_2fa' => 'tr#wfls-user-2fa-status, tr.user-wordfence-ls-wrap',
        'wordfence-2fa' => 'tr#wfls-user-2fa-status, tr.user-wordfence-ls-wrap',
        'wordfence-ls' => 'tr#wfls-user-2fa-status, tr.user-wordfence-ls-wrap',
        'wordfence_passkeys' => 'tr#wfls-user-passkey-status, tr.user-wordfence-ls-passkey-wrap',
        'wordfence-passkeys' => 'tr#wfls-user-passkey-status, tr.user-wordfence-ls-passkey-wrap',
        'wordfence-ls-passkey' => 'tr#wfls-user-passkey-status, tr.user-wordfence-ls-passkey-wrap',
        'yoast' => '#yoast-seo-settings, tr[class*="yoast"], input[name^="wpseo_"]',
        'yoast_seo' => '#yoast-seo-settings, tr[class*="yoast"], input[name^="wpseo_"]',
        'yoast-seo' => '#yoast-seo-settings, tr[class*="yoast"], input[name^="wpseo_"]',
        'wpseo' => '#yoast-seo-settings, tr[class*="yoast"], input[name^="wpseo_"]',
        'ai-generator-consent' => '#ai-generator-consent, button#ai-generator-consent-button, '
            . 'label[for="ai-generator-consent-button"]',
        'ai_generator_consent' => '#ai-generator-consent, button#ai-generator-consent-button, '
            . 'label[for="ai-generator-consent-button"]',
    ];

    /**
     * @return array<string, string>
     */
    public static function getProfileFieldSelectors(): array
    {
        return self::PROFILE_FIELD_SELECTORS_MAP;
    }

    public static function getProfileFieldSelector(string $field): ?string
    {
        return self::PROFILE_FIELD_SELECTORS_MAP[$field] ?? null;
    }
}
