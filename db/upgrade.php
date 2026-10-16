<?php
// This file is part of local_downloadcentercustom for Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Upgrade code for local_downloadcentercustom.
 *
 * @package   local_downloadcentercustom
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_downloadcentercustom_upgrade($oldversion) {
    global $DB;

    if ($oldversion < 2026101601) {
        // Por defecto, teacher y editingteacher quedan SOLO con modo portafolio:
        // se revoca 'downloadnormal' de esos roles en el contexto de sistema
        // (el rol manager lo conserva). El archetype de db/access.php solo aplica
        // a instalaciones nuevas, por eso aquí se corrige lo ya existente.
        $capability = 'local/downloadcentercustom:downloadnormal';
        $systemcontext = \context_system::instance();
        foreach (['teacher', 'editingteacher'] as $shortname) {
            $role = $DB->get_record('role', ['shortname' => $shortname]);
            if ($role) {
                unassign_capability($capability, $role->id, $systemcontext->id);
            }
        }

        upgrade_plugin_savepoint(true, 2026101601, 'local', 'downloadcentercustom');
    }

    return true;
}
