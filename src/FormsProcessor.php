<?php

namespace slateos\formsprocessor;

use Craft;
use craft\base\Plugin;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use craft\web\View;
use craft\web\twig\variables\Cp;
use slateos\formsprocessor\models\Settings;
use slateos\formsprocessor\services\FormTypeService;
use slateos\formsprocessor\services\PdfTemplateService;
use slateos\formsprocessor\services\SlateService;
use slateos\formsprocessor\services\SubmissionService;
use yii\base\Event;

/**
 * Forms Processor plugin for Craft CMS 5.
 *
 * CP sections:
 *   - Settings      — global defaults (email sender, paths, rate limits, paper size)
 *   - Form Types    — per-form configuration (Slate endpoint, PDF/email templates, fields map)
 *   - PDF Templates — CodeMirror Twig editor + sample data + live preview
 *   - Submissions   — local backup of all processed submissions
 *
 * Front-end endpoint:
 *   POST /actions/forms-processor/process/submit
 */
class FormsProcessor extends Plugin
{
    public static FormsProcessor $plugin;

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        // Register @slateos/formsprocessor alias for icon path
        Craft::setAlias('@slateos/formsprocessor', __DIR__);

        $this->setComponents([
            'formTypes'    => FormTypeService::class,
            'pdfTemplates' => PdfTemplateService::class,
            'submissions'  => SubmissionService::class,
            'slate'        => SlateService::class,
        ]);

        // Register CP template root so Craft can find templates/forms-processor/**
        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            function (RegisterTemplateRootsEvent $event) {
                $event->roots['forms-processor'] = __DIR__ . '/../templates/forms-processor';
            }
        );

        // CP nav
        Event::on(
            Cp::class,
            Cp::EVENT_REGISTER_CP_NAV_ITEMS,
            function (RegisterCpNavItemsEvent $event) {
                $event->navItems[] = [
                    'url'    => 'forms-processor',
                    'label'  => 'Forms Processor',
                    'icon'   => '@slateos/formsprocessor/icon.svg',
                    'subnav' => [
                        'form-types'    => ['label' => 'Form Types',    'url' => 'forms-processor/form-types'],
                        'pdf-templates' => ['label' => 'PDF Templates', 'url' => 'forms-processor/pdf-templates'],
                        'submissions'   => ['label' => 'Submissions',   'url' => 'forms-processor/submissions'],
                        'settings'      => ['label' => 'Settings',      'url' => 'forms-processor/settings'],
                    ],
                ];
            }
        );

        // CP URL rules
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules = array_merge($event->rules, [
                    'forms-processor'                              => 'forms-processor/form-types/index',
                    'forms-processor/form-types'                   => 'forms-processor/form-types/index',
                    'forms-processor/form-types/new'               => 'forms-processor/form-types/edit',
                    'forms-processor/form-types/<id:\d+>'          => 'forms-processor/form-types/edit',
                    'forms-processor/pdf-templates'                => 'forms-processor/pdf-templates/index',
                    'forms-processor/pdf-templates/new'            => 'forms-processor/pdf-templates/edit',
                    'forms-processor/pdf-templates/<id:\d+>'       => 'forms-processor/pdf-templates/edit',
                    'forms-processor/submissions'                  => 'forms-processor/submissions/index',
                    'forms-processor/submissions/<formTypeId:\d+>' => 'forms-processor/submissions/index',
                    'forms-processor/settings'                     => 'forms-processor/settings/index',
                ]);
            }
        );

        // Front-end URL rules (site requests)
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules = array_merge($event->rules, [
                    'forms-processor/submit'       => 'forms-processor/process/submit',
                    'forms-processor/pdf/<token>'  => 'forms-processor/process/pdf',
                ]);
            }
        );

        Craft::info('Forms Processor plugin loaded', __METHOD__);
    }

    // ── Settings model ────────────────────────────────────────────────────────

    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(
            \craft\helpers\UrlHelper::cpUrl('forms-processor/settings')
        );
    }

    // ── Install / uninstall ───────────────────────────────────────────────────

    protected function afterInstall(): void
    {
        if (Craft::$app->getRequest()->getIsCpRequest()) {
            Craft::$app->getResponse()->redirect(
                \craft\helpers\UrlHelper::cpUrl('forms-processor/settings')
            )->send();
        }
    }
}
