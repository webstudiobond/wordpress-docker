<?php

declare(strict_types=1);

namespace WordPressDocker\AdminControl\Dom;

use WordPressDocker\AdminControl\Config\SelectorsMap;

/**
 * Sanitizes administrative profile markup by removing restricted form fields, section headers, and empty containers.
 *
 * @phpstan-import-type AdminRule from \WordPressDocker\AdminControl\Security\RuleMatcher
 */
final class ProfileDomCleaner
{
    private bool $isBufferingStarted = false;

    /**
     * @param (callable(): list<string>)|null $fieldsResolver
     * @param (callable(string): ?\DOMDocument)|null $docLoader
     * @param (callable(string): (string|false))|null $envGetter
     * @param (callable(): (?object))|null $currentUserGetter
     * @param (callable(string): list<AdminRule>)|null $ruleParser
     * @param (callable(AdminRule, ?object): bool)|null $userMatcher
     * @param (callable(): string)|null $pageNowGetter
     * @param (callable(string, callable|string, int): void)|null $actionRemover
     * @param (callable(string): void)|null $htmlEchoer
     * @param (callable(list<string>): void)|null $postGuardSanitizer
     * @param (callable(callable): bool)|null $bufferStarter
     */
    public function __construct(
        private readonly mixed $fieldsResolver = null,
        private readonly mixed $docLoader = null,
        private readonly mixed $envGetter = null,
        private readonly mixed $currentUserGetter = null,
        private readonly mixed $ruleParser = null,
        private readonly mixed $userMatcher = null,
        private readonly mixed $pageNowGetter = null,
        private readonly mixed $actionRemover = null,
        private readonly mixed $htmlEchoer = null,
        private readonly mixed $postGuardSanitizer = null,
        private readonly mixed $bufferStarter = null,
    ) {
    }

    public function isBufferingStarted(): bool
    {
        return $this->isBufferingStarted;
    }

    /**
     * @param (callable(): list<string>)|null $fieldsResolver
     */
    public function startOutputBuffering(?callable $fieldsResolver = null): void
    {
        if ($this->isBufferingStarted) {
            return;
        }

        $this->isBufferingStarted = true;
        $handler = function (string $buffer) use ($fieldsResolver): string {
            return $this->filterBuffer($buffer, $fieldsResolver);
        };

        if (\is_callable($this->bufferStarter)) {
            ($this->bufferStarter)($handler);
            return;
        }

        \ob_start($handler);
    }

    /**
     * @param (callable(): list<string>)|null $fieldsResolver
     */
    public function filterBuffer(string $buffer, ?callable $fieldsResolver = null): string
    {
        $resolver = $fieldsResolver ?? $this->fieldsResolver;
        if ($resolver !== null) {
            $fieldsToRemove = (array) $resolver();
        } else {
            $fieldsToRemove = $this->getProfileFieldsToRemoveForUser();
        }

        if ($fieldsToRemove === []) {
            return $buffer;
        }

        return $this->removeProfileFieldsFromHtml($buffer, $fieldsToRemove);
    }

    /**
     * @param list<string> $fieldsToRemove
     */
    public function removeProfileFieldsFromHtml(string $buffer, array $fieldsToRemove): string
    {
        if ($fieldsToRemove === []) {
            return $buffer;
        }

        $formPattern = '/(<form\b[^>]*\bid=["\'](?:your-profile|createuser)["\'][^>]*>)'
            . '(.*?)(<\/form>)/is';
        if (\preg_match($formPattern, $buffer, $formMatch) !== 1) {
            return $buffer;
        }

        $formOpen = $formMatch[1];
        $formInner = $formMatch[2];
        $formClose = $formMatch[3];

        if (\trim($formInner) === '') {
            return $buffer;
        }

        if ($this->docLoader !== null) {
            $doc = ($this->docLoader)($formInner);
        } else {
            $doc = new \DOMDocument();
            \libxml_use_internal_errors(true);
            $encoded = \mb_encode_numericentity($formInner, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
            $htmlPayload = '<?xml encoding="utf-8" ?><body><div id="__wp_form_root">'
                . $encoded . '</div></body>';
            $doc->loadHTML($htmlPayload, \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD);
            \libxml_clear_errors();
        }

        if (!$doc instanceof \DOMDocument) {
            return $buffer;
        }

        $xpath = new \DOMXPath($doc);
        $root = $doc->getElementById('__wp_form_root');
        if (!$root instanceof \DOMElement) {
            return $buffer;
        }

        foreach ($fieldsToRemove as $target) {
            $normalizedTarget = \strtolower(\trim($target));
            if ($normalizedTarget === '') {
                continue;
            }

            $queries = $this->buildProfileFieldXPathQueries($normalizedTarget);
            foreach ($queries as $q) {
                $nodes = $xpath->query($q, $root);
                if ($nodes instanceof \DOMNodeList) {
                    foreach ($nodes as $node) {
                        if ($node instanceof \DOMElement) {
                            $this->removeElementWithAriaDescribedBy($xpath, $root, $node);
                        }
                    }
                }
            }
        }

        $tables = $xpath->query('.//table', $root);
        if ($tables instanceof \DOMNodeList) {
            /** @var iterable<\DOMElement> $tables */
            foreach ($tables as $table) {
                $rows = $xpath->query('.//tr', $table);
                if ($rows instanceof \DOMNodeList) {
                    /** @var iterable<\DOMElement> $rows */
                    $this->cleanTableHeadingRows($xpath, $rows);
                }
                $remainingRows = $xpath->query('.//tr', $table);
                if ($remainingRows instanceof \DOMNodeList && $remainingRows->length === 0) {
                    $this->removePrecedingHeading($table);
                    if ($table->parentNode instanceof \DOMNode) {
                        $table->parentNode->removeChild($table);
                    }
                }
            }
        }

        $containerQuery = './/*[@id="wfls-user-settings" or @id="yoast-seo-settings" '
            . 'or contains(concat(" ", normalize-space(@class), " "), " yoast ") '
            . 'or contains(concat(" ", normalize-space(@class), " "), " yoast-settings ") '
            . 'or contains(concat(" ", normalize-space(@class), " "), " application-passwords ")]';
        $containers = $xpath->query($containerQuery, $root);
        if ($containers instanceof \DOMNodeList) {
            /** @var iterable<\DOMElement> $containers */
            foreach ($containers as $c) {
                $hasRows = $xpath->query('.//tr', $c);
                $isEmpty = false;
                if ($hasRows instanceof \DOMNodeList && $hasRows->length > 0) {
                    $isEmpty = false;
                } else {
                    $activeControls = $xpath->query('.//input | .//textarea | .//select | .//button', $c);
                    $isEmpty = ($activeControls instanceof \DOMNodeList && $activeControls->length === 0);
                }

                if ($isEmpty) {
                    $this->removePrecedingHeading($c);
                    if ($c->parentNode instanceof \DOMNode) {
                        $c->parentNode->removeChild($c);
                    }
                }
            }
        }

        $newInner = '';
        foreach ($root->childNodes as $child) {
            $saved = $doc->saveHTML($child);
            if (\is_string($saved)) {
                $newInner .= $saved;
            }
        }

        $decodedInner = \mb_decode_numericentity($newInner, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');

        return \str_replace($formMatch[0], $formOpen . $decodedInner . $formClose, $buffer);
    }

    /**
     * @return list<string>
     */
    public function buildProfileFieldXPathQueries(string $target): array
    {
        $normalized = \strtolower(\trim($target));

        if (\in_array($normalized, ['user_login', 'user-login', 'username'], true)) {
            return [
                './/tr[contains(concat(" ", normalize-space(@class), " "), " user-user-login-wrap ")]',
                './/tr[contains(concat(" ", normalize-space(@class), " "), " user-login-wrap ")]',
                './/tr[.//input[@id="user_login" or @name="user_login"]]',
                './/tr[.//label[@for="user_login"]]',
            ];
        }

        if (\in_array($normalized, ['url', 'website'], true)) {
            return [
                './/tr[contains(concat(" ", normalize-space(@class), " "), " user-url-wrap ")]',
                './/tr[.//input[@id="url" or @name="url"]]',
                './/tr[.//label[@for="url"]]',
            ];
        }

        if (\in_array($normalized, ['description', 'bio'], true)) {
            return [
                './/tr[contains(concat(" ", normalize-space(@class), " "), " user-description-wrap ")]',
                './/tr[contains(@class, "user-description-")]',
                './/tr[.//textarea[starts-with(@name, "description")]]',
                './/tr[.//label[starts-with(@for, "description")]]',
            ];
        }

        if (\in_array($normalized, ['profile_picture', 'avatar', 'profile-picture'], true)) {
            return [
                './/tr[contains(concat(" ", normalize-space(@class), " "), " user-profile-picture-wrap ")]',
                './/tr[contains(concat(" ", normalize-space(@class), " "), " user-profile-picture ")]',
            ];
        }

        if (\in_array($normalized, ['about', 'about_yourself', 'about-yourself'], true)) {
            return [
                './/tr[contains(concat(" ", normalize-space(@class), " "), " user-description-wrap ")]',
                './/tr[contains(@class, "user-description-")]',
                './/tr[.//textarea[starts-with(@name, "description")]]',
                './/tr[contains(concat(" ", normalize-space(@class), " "), " user-profile-picture-wrap ")]',
                './/tr[contains(concat(" ", normalize-space(@class), " "), " user-profile-picture ")]',
            ];
        }

        if (\in_array($normalized, ['wordfence', 'wfls', 'wordfence_2fa', 'wordfence_passkeys'], true)) {
            return [
                './/*[@id="wfls-user-settings"]',
                './/tr[@id="wfls-user-2fa-status"]',
                './/tr[@id="wfls-user-passkey-status"]',
                './/tr[contains(@class, "user-wordfence-ls")]',
            ];
        }

        if (\in_array($normalized, ['yoast', 'yoast_seo', 'yoast-seo', 'wpseo'], true)) {
            return [
                './/*[@id="yoast-seo-settings"]',
                './/*[contains(concat(" ", normalize-space(@class), " "), " yoast-settings ")]',
                './/*[contains(concat(" ", normalize-space(@class), " "), " yoast ")]',
                './/tr[contains(@class, "yoast")]',
                './/input[starts-with(@name, "wpseo_")]',
            ];
        }

        if (\in_array($normalized, ['elementor_ai', 'elementor-ai', 'elementor_enable_ai'], true)) {
            return [
                './/tr[contains(concat(" ", normalize-space(@class), " "), " user-elementor_enable_ai-wrap ")]',
                './/tr[.//input[contains(@name, "elementor_enable_ai")]]',
                './/tr[.//h2[contains(text(), "Elementor - AI") or contains(text(), "Elementor AI")]]',
                './/tr[.//h3[contains(text(), "Elementor - AI") or contains(text(), "Elementor AI")]]',
            ];
        }

        if (\in_array($normalized, ['elementor'], true)) {
            return [
                './/tr[contains(@class, "elementor")]',
                './/tr[.//input[contains(@name, "elementor")]]',
                './/tr[.//label[contains(@for, "elementor")]]',
                './/tr[.//h2[contains(text(), "Elementor")]]',
                './/tr[.//h3[contains(text(), "Elementor")]]',
            ];
        }

        if (
            \in_array(
                $normalized,
                ['ai_features', 'ai-features', 'ai_generator_consent', 'ai-generator-consent'],
                true
            )
        ) {
            return [
                './/*[@id="ai-generator-consent"]',
                './/*[contains(concat(" ", normalize-space(@class), " "), " yoast-settings ") '
                    . 'and .//*[@id="ai-generator-consent" or .//button[@id="ai-generator-consent-button"]]]',
                './/button[@id="ai-generator-consent-button"]',
                './/label[@for="ai-generator-consent-button"]',
            ];
        }

        $sanitized = \preg_replace('/[^a-z0-9_-]/', '', $normalized) ?? '';
        if ($sanitized === '') {
            return [];
        }

        return [
            './/tr[contains(concat(" ", normalize-space(@class), " "), " user-' . $sanitized . '-wrap ")]',
            './/tr[contains(concat(" ", normalize-space(@class), " "), " ' . $sanitized . '-wrap ")]',
            './/tr[@id="' . $sanitized . '"]',
            './/tr[.//input[@id="' . $sanitized . '" or @name="' . $sanitized . '"]]',
            './/tr[.//textarea[@id="' . $sanitized . '" or @name="' . $sanitized . '"]]',
            './/tr[.//select[@id="' . $sanitized . '" or @name="' . $sanitized . '"]]',
            './/tr[.//label[@for="' . $sanitized . '"]]',
            './/label[@for="' . $sanitized . '"]',
            './/*[@id="' . $sanitized . '"]',
            './/*[@id="' . $sanitized . '_desc"]',
            './/*[@id="' . $sanitized . '-desc"]',
            './/*[@id="' . $sanitized . '-description"]',
        ];
    }

    private function removeElementWithAriaDescribedBy(\DOMXPath $xpath, \DOMElement $root, \DOMElement $element): void
    {
        $describedBy = \trim($element->getAttribute('aria-describedby'));
        if ($describedBy !== '') {
            foreach (\explode(' ', $describedBy) as $descId) {
                $trimmedId = \trim($descId);
                if ($trimmedId === '' || \preg_match('/^[a-zA-Z0-9_-]+$/', $trimmedId) !== 1) {
                    continue;
                }
                $descNodes = $xpath->query('.//*[@id="' . $trimmedId . '"]', $root);
                if ($descNodes instanceof \DOMNodeList) {
                    foreach ($descNodes as $d) {
                        if ($d instanceof \DOMNode && $d->parentNode instanceof \DOMNode) {
                            $d->parentNode->removeChild($d);
                        }
                    }
                }
            }
        }

        if ($element->parentNode instanceof \DOMNode) {
            $element->parentNode->removeChild($element);
        }
    }

    /**
     * @param iterable<\DOMElement> $rows
     */
    private function cleanTableHeadingRows(\DOMXPath $xpath, iterable $rows): void
    {
        /** @var list<\DOMElement> $headingRows */
        $headingRows = [];
        foreach ($rows as $row) {
            $controls = $xpath->query('.//input | .//textarea | .//select | .//button', $row);
            $headings = $xpath->query('.//h1 | .//h2 | .//h3 | .//h4 | .//h5 | .//h6', $row);
            if (
                $controls instanceof \DOMNodeList
                && $controls->length === 0
                && $headings instanceof \DOMNodeList
                && $headings->length > 0
            ) {
                $headingRows[] = $row;
            }
        }

        foreach ($headingRows as $hRow) {
            $hasControlsFollowing = false;
            $next = $hRow->nextSibling;
            while ($next !== null) {
                if ($next instanceof \DOMElement && $next->nodeName === 'tr') {
                    $nextHeadings = $xpath->query('.//h1 | .//h2 | .//h3 | .//h4 | .//h5 | .//h6', $next);
                    $nextControls = $xpath->query('.//input | .//textarea | .//select | .//button', $next);
                    if (
                        $nextControls instanceof \DOMNodeList
                        && $nextControls->length === 0
                        && $nextHeadings instanceof \DOMNodeList
                        && $nextHeadings->length > 0
                    ) {
                        break;
                    }
                    if ($nextControls instanceof \DOMNodeList && $nextControls->length > 0) {
                        $hasControlsFollowing = true;
                        break;
                    }
                }
                $next = $next->nextSibling;
            }

            if (!$hasControlsFollowing && $hRow->parentNode instanceof \DOMNode) {
                $hRow->parentNode->removeChild($hRow);
            }
        }
    }

    private function removePrecedingHeading(\DOMElement $element): void
    {
        $prev = $element->previousSibling;
        while ($prev !== null) {
            $curr = $prev;
            $prev = $prev->previousSibling;
            if ($curr instanceof \DOMElement) {
                if (\preg_match('/^h[1-6]$/i', $curr->nodeName) === 1 && $curr->parentNode instanceof \DOMNode) {
                    $curr->parentNode->removeChild($curr);
                }
                break;
            }
        }
    }

    /**
     * @return list<string>
     */
    public function getProfileFieldsToRemoveForUser(?object $user = null): array
    {
        $raw = $this->getEnv('WP_ADMIN_PROFILE_FIELDS_REMOVE');
        if ($raw === false || \trim($raw) === '') {
            return [];
        }

        if ($this->ruleParser === null) {
            return [];
        }

        $currentUser = $user ?? $this->getCurrentUser();
        $rules = ($this->ruleParser)(\trim($raw));
        $fields = [];
        foreach ($rules as $rule) {
            if ($this->userMatcher !== null && ($this->userMatcher)($rule, $currentUser)) {
                foreach ($rule['targets'] as $target) {
                    $trimmed = \strtolower(\trim($target));
                    if ($trimmed !== '') {
                        $fields[] = $trimmed;
                    }
                }
            }
        }

        return \array_values(\array_unique($fields));
    }

    /**
     * @param array<string, string> $methods
     * @param ?object $user
     * @return array<string, string>
     */
    public function filterUserContactMethods(array $methods, ?object $user = null): array
    {
        $currentUser = $user ?? $this->getCurrentUser();
        $fieldsToRemove = $this->getProfileFieldsToRemoveForUser($currentUser);
        if ($fieldsToRemove === []) {
            return $methods;
        }

        foreach ($fieldsToRemove as $field) {
            unset($methods[$field]);
            if ($field === 'website') {
                unset($methods['url']);
            }
            if ($field === 'url') {
                unset($methods['website']);
            }
            foreach (\array_keys($methods) as $key) {
                if (\strtolower($key) === $field) {
                    unset($methods[$key]);
                }
            }
        }

        return $methods;
    }

    public function filterShowAdminBar(bool $show): bool
    {
        $currentUser = $this->getCurrentUser();
        $fieldsToRemove = $this->getProfileFieldsToRemoveForUser($currentUser);
        if (
            \in_array('admin_bar_front', $fieldsToRemove, true)
            || \in_array('toolbar', $fieldsToRemove, true)
            || \in_array('admin_bar', $fieldsToRemove, true)
        ) {
            return false;
        }

        return $show;
    }

    public function handleAdminInit(): void
    {
        $currentUser = $this->getCurrentUser();
        $fieldsToRemove = $this->getProfileFieldsToRemoveForUser($currentUser);
        if ($fieldsToRemove === []) {
            return;
        }

        if (\in_array('color_scheme', $fieldsToRemove, true) || \in_array('admin_color', $fieldsToRemove, true)) {
            $this->removeAction('admin_color_scheme_picker', 'admin_color_scheme_picker');
        }

        $pagenow = $this->getPageNow();
        if ($pagenow === 'profile.php' || $pagenow === 'user-edit.php') {
            $this->startOutputBuffering(fn (): array => $this->getProfileFieldsToRemoveForUser());
            if (\is_callable($this->postGuardSanitizer)) {
                ($this->postGuardSanitizer)($fieldsToRemove);
            }
        }
    }

    public function handleAdminHead(): void
    {
        $currentUser = $this->getCurrentUser();
        $fieldsToRemove = $this->getProfileFieldsToRemoveForUser($currentUser);
        if ($fieldsToRemove === []) {
            return;
        }

        $selectors = [];
        foreach ($fieldsToRemove as $field) {
            if (isset(SelectorsMap::PROFILE_FIELD_SELECTORS_MAP[$field])) {
                $selectors[] = SelectorsMap::PROFILE_FIELD_SELECTORS_MAP[$field];
            } else {
                $sanitizedField = \preg_replace('/[^a-z0-9_-]/', '', $field) ?? '';
                if ($sanitizedField !== '') {
                    $selectors[] = \sprintf(
                        'tr.user-%1$s-wrap, tr.%1$s-wrap, tr#%1$s, input[name="%1$s"], '
                        . 'textarea[name="%1$s"], select[name="%1$s"], label[for="%1$s"]',
                        $sanitizedField
                    );
                }
            }
        }

        $selectors = \array_values(\array_unique($selectors));
        $jsonSelectors = (string) \json_encode($selectors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->echoHtml(
            '<script id="wp-admin-control-clean-profile-sections">'
            . '(function(){function removeProfileElements(){'
            . 'var f=document.querySelectorAll("#your-profile, form[action*=\"profile.php\"], '
            . 'form[action*=\"user-edit.php\"]");'
            . 'if(!f.length)return;'
            . 'var s=' . $jsonSelectors . ';'
            . 'f.forEach(function(form){'
            . 's.forEach(function(sel){'
            . 'try{'
            . 'form.querySelectorAll(sel).forEach(function(el){'
            . 'var target=el.closest("tr, div.application-passwords, #wfls-user-settings, '
            . '#yoast-seo-settings")||el;'
            . 'target.remove();'
            . '});'
            . '}catch(e){}'
            . '});'
            . 'form.querySelectorAll(".form-table, table").forEach(function(table){'
            . 'if(!table.querySelectorAll("tr").length){'
            . 'var prev=table.previousElementSibling;'
            . 'while(prev&&!/^H[1-6]$/i.test(prev.tagName)){'
            . 'var temp=prev.previousElementSibling;'
            . 'if(prev.classList.contains("description")||prev.tagName==="P"){prev.remove();}'
            . 'prev=temp;'
            . '}'
            . 'if(prev&&/^H[1-6]$/i.test(prev.tagName)){prev.remove();}'
            . 'table.remove();'
            . '}'
            . '});'
            . 'form.querySelectorAll("#wfls-user-settings, #yoast-seo-settings, '
            . '.application-passwords").forEach(function(c){'
            . 'if(c.style.display==="none"||!c.children.length){'
            . 'var prev=c.previousElementSibling;'
            . 'while(prev&&!/^H[1-6]$/i.test(prev.tagName)){prev=prev.previousElementSibling;}'
            . 'if(prev&&/^H[1-6]$/i.test(prev.tagName)){prev.remove();}'
            . 'c.remove();'
            . '}'
            . '});'
            . '});'
            . '}'
            . 'if(document.readyState==="loading"){document.addEventListener('
            . '"DOMContentLoaded",removeProfileElements);}'
            . 'else{removeProfileElements();}'
            . 'try{var pForm=document.querySelector("#your-profile");if(pForm&&window.MutationObserver){'
            . 'new MutationObserver(removeProfileElements).observe(pForm,{childList:true,subtree:true});'
            . '}}catch(e){}'
            . '})();'
            . '</script>'
        );
    }

    private function getEnv(string $name): string|false
    {
        if (\is_callable($this->envGetter)) {
            return ($this->envGetter)($name);
        }

        return \getenv($name, true);
    }

    private function getCurrentUser(): ?object
    {
        if (\is_callable($this->currentUserGetter)) {
            $user = ($this->currentUserGetter)();
            return \is_object($user) ? $user : null;
        }

        $user = \function_exists('wp_get_current_user') ? \wp_get_current_user() : null;
        return \is_object($user) ? $user : null;
    }

    private function getPageNow(): string
    {
        if (\is_callable($this->pageNowGetter)) {
            return ($this->pageNowGetter)();
        }

        $raw = $GLOBALS['pagenow'] ?? '';
        return \is_string($raw) ? \trim($raw) : '';
    }

    private function removeAction(string $tag, callable|string $functionToAdd, int $priority = 10): void
    {
        if (\is_callable($this->actionRemover)) {
            ($this->actionRemover)($tag, $functionToAdd, $priority);
            return;
        }

        if (\function_exists('remove_action')) {
            \remove_action($tag, $functionToAdd, $priority);
        }
    }

    private function echoHtml(string $html): void
    {
        if (\is_callable($this->htmlEchoer)) {
            ($this->htmlEchoer)($html);
            return;
        }

        echo $html;
    }
}
