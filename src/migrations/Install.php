<?php

namespace slateos\formsprocessor\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        // ── PDF Templates ─────────────────────────────────────────────────────
        $this->createTable('{{%formsprocessor_pdf_templates}}', [
            'id'          => $this->primaryKey(),
            'name'        => $this->string(255)->notNull(),
            'bodyTwig'    => $this->mediumText()->notNull()->defaultValue(''),
            'headerTwig'  => $this->text()->notNull()->defaultValue(''),
            'footerTwig'  => $this->text()->notNull()->defaultValue(''),
            'sampleData'  => $this->mediumText()->notNull()->defaultValue('{}'),
            'paperSize'   => $this->string(10)->notNull()->defaultValue('A4'),
            'margins'     => $this->string(50)->notNull()->defaultValue('[10,10,10,10]'),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid'         => $this->uid(),
        ]);

        // ── Form Types ────────────────────────────────────────────────────────
        $this->createTable('{{%formsprocessor_form_types}}', [
            'id'                => $this->primaryKey(),
            'name'              => $this->string(255)->notNull(),
            'handle'            => $this->string(255)->notNull()->unique(),
            'slateEndpoint'     => $this->string(512)->notNull()->defaultValue(''),
            'slateApiKey'       => $this->string(255)->notNull()->defaultValue(''),
            'slateSource'       => $this->string(255)->notNull()->defaultValue(''),
            'pdfTemplateId'     => $this->integer()->null(),
            'emailTemplatePath' => $this->string(512)->notNull()->defaultValue(''),
            'emailSubject'      => $this->string(512)->notNull()->defaultValue(''),
            'emailBody'         => $this->text()->notNull()->defaultValue(''),
            'bccOverride'       => $this->string(512)->notNull()->defaultValue(''),
            'rateLimitMax'      => $this->integer()->null(),
            'rateLimitWindow'   => $this->integer()->null(),
            'fieldsMap'         => $this->text()->notNull()->defaultValue('{}'),
            'enabled'           => $this->boolean()->notNull()->defaultValue(true),
            'dateCreated'       => $this->dateTime()->notNull(),
            'dateUpdated'       => $this->dateTime()->notNull(),
            'uid'               => $this->uid(),
        ]);

        $this->addForeignKey(
            null,
            '{{%formsprocessor_form_types}}', 'pdfTemplateId',
            '{{%formsprocessor_pdf_templates}}', 'id',
            'SET NULL', 'CASCADE'
        );

        // ── Submissions ───────────────────────────────────────────────────────
        $this->createTable('{{%formsprocessor_submissions}}', [
            'id'                => $this->primaryKey(),
            'formTypeId'        => $this->integer()->notNull(),
            'contactName'       => $this->string(255)->notNull()->defaultValue(''),
            'contactEmail'      => $this->string(255)->notNull()->defaultValue(''),
            'contactPhone'      => $this->string(50)->notNull()->defaultValue(''),
            'payload'           => $this->mediumText()->notNull()->defaultValue('{}'),
            'slateSubmissionId' => $this->string(255)->notNull()->defaultValue(''),
            'status'            => $this->enum('status', ['pending', 'sent', 'failed'])->notNull()->defaultValue('pending'),
            'dateCreated'       => $this->dateTime()->notNull(),
            'dateUpdated'       => $this->dateTime()->notNull(),
            'uid'               => $this->uid(),
        ]);

        $this->addForeignKey(
            null,
            '{{%formsprocessor_submissions}}', 'formTypeId',
            '{{%formsprocessor_form_types}}', 'id',
            'CASCADE', 'CASCADE'
        );

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%formsprocessor_submissions}}');
        $this->dropTableIfExists('{{%formsprocessor_form_types}}');
        $this->dropTableIfExists('{{%formsprocessor_pdf_templates}}');
        return true;
    }
}
