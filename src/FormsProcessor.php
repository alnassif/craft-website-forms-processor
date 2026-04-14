<?php

namespace slateos\formsprocessor;

use Craft;
use craft\base\Plugin;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use craft\web\twig\variables\Cp;
use slateos\formsprocessor\models\Settings;
use slateos\formsprocessor\services\FormTypeService;
use slateos\formsprocessor\services\PdfTemplateService;
use slateos\formsprocessor\services\SubmissionService;
use yii\base\Event;

/**
 * Forms Processor plugin for Craft CMS 5.
 *
 * CP sections:
 *   - Settings  — global defaults (email sender, paths, rate limits, paper size)
 *   - Form Types — per-form configuration (Slate endpoint, PDF/email templates, fields map)
 *   - PDF Templates — CodeMirror Twig editor + sample data + live preview
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

        $this->setComponents([
            'formTypes'    => FormTypeService::class,
            'pdfTemplates' => PdfTemplateService::class,
            'submissions'  => SubmissionService::class,
        ]);

        // CP nav
        Event::on(
            Cp::class,
            Cp::EVENT_REGISTER_CP_NAV_ITEMS,
            function (RegisterCpNavItemsEvent $event) {
                $event->navItems[] = [
                    'url'      => 'forms-processor',
                    'label'    => 'Forms Processor',
                    'icon'     => '@slateos/formsprocessor/icon.svg',
                    'subnav'   => [
                        'form-types'    => ['label' => 'Form Types',    'url' => 'forms-processor/form-types'],
                        'pdf-templates' => ['label' => 'PDF Templates', 'url' => 'forms-processor/pdf-templates'],
                        'submissions'   => ['label' => 'Submissions',   'url' => 'forms-processor/submissions'],
                        'settings'      => ['label' => 'Settings',      'url' => 'forms-processor/settings'],
                    ],
                ];
            }
        );

        // CP routes
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules = array_merge($event->rules, [
                    'forms-processor'                                  => 'forms-processor/form-types/index',
                    'forms-processor/form-types'                       => 'forms-processor/form-types/index',
                    'forms-processor/form-types/new'                   => 'forms-processor/form-types/edit',
                    'forms-processor/form-types/<id:\d+>'              => 'forms-processor/form-types/edit',
                    'forms-processor/pdf-templates'                    => 'forms-processor/pdf-templates/index',
                    'forms-processor/pdf-templates/new'                => 'forms-processor/pdf-templates/edit',
                    'forms-processor/pdf-templates/<id:\d+>'           => 'forms-processor/pdf-templates/edit',
                    'forms-processor/submissions'                      => 'forms-processor/submissions/index',
                    'forms-processor/submissions/<formTypeId:\d+>'     => 'forms-processor/submissions/index',
                    'forms-processor/settings'                         => 'forms-processor/settings/index',
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
        // Redirect to settings after install
        if (Craft::$app->getRequest()->getIsCpRequest()) {
            Craft::$app->getResponse()->redirect(
                \craft\helpers\UrlHelper::cpUrl('forms-processor/settings')
            )->send();
        }
    }
}
