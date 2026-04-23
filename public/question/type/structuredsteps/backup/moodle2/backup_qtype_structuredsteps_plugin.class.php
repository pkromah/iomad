<?php
// This file is part of Moodle - http://moodle.org/

/**
 * @package   qtype_structuredsteps
 * @copyright 2026 Pennacool
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Provides backup data for qtype_structuredsteps.
 */
class backup_qtype_structuredsteps_plugin extends backup_qtype_plugin {
    /**
     * Returns the paths to be handled by the plugin at question level.
     *
     * @return array
     */
    protected function define_question_plugin_structure() {
        $plugin = $this->get_plugin_element(null, '../../qtype', 'structuredsteps');
        $pluginwrapper = new backup_nested_element($this->get_recommended_name());

        $structuredsteps = new backup_nested_element('structuredsteps', ['id'], [
            'schema_version',
            'engine',
            'engine_version',
            'model_json',
        ]);

        $plugin->add_child($pluginwrapper);
        $pluginwrapper->add_child($structuredsteps);

        $structuredsteps->set_source_table('qtype_structuredsteps_options', ['questionid' => backup::VAR_PARENTID]);

        return [$plugin];
    }
}
