<?php
// This file is part of local_centro_de_descarga_de_portafolio_de_evidencias for Moodle - http://moodle.org/
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
 * Install code for local_centro_de_descarga_de_portafolio_de_evidencias.
 *
 * Variante personalizada (fork) de local_downloadcenter, adaptada para la UTJ.
 *
 * @package   local_centro_de_descarga_de_portafolio_de_evidencias
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Migrate role permissions from the previous component name, if present.
 *
 * Al renombrar el plugin (local_downloadcentercustom -> local_centro_...),
 * Moodle instala un componente nuevo y crea sus capabilities con los archetypes
 * por defecto. Este paso copia los permisos existentes del componente anterior
 * para no perder ajustes de roles/contextos hechos por el administrador.
 *
 * @return bool
 */
function xmldb_local_centro_de_descarga_de_portafolio_de_evidencias_install() {
    global $DB;

    $oldcomponent = 'local_downloadcentercustom';
    $newcomponent = 'local_centro_de_descarga_de_portafolio_de_evidencias';

    $oldcaps = $DB->get_records('capabilities', ['component' => $oldcomponent]);
    if (!$oldcaps) {
        return true;
    }

    foreach ($oldcaps as $oldcap) {
        $newcap = 'local/' . $newcomponent . ':' . $oldcap->name;
        $grants = $DB->get_records('role_capabilities', ['capability' => $oldcap->capability]);
        foreach ($grants as $grant) {
            $exists = $DB->record_exists('role_capabilities', [
                'roleid' => $grant->roleid,
                'contextid' => $grant->contextid,
                'capability' => $newcap,
            ]);
            if (!$exists) {
                $DB->insert_record('role_capabilities', (object) [
                    'roleid' => $grant->roleid,
                    'contextid' => $grant->contextid,
                    'capability' => $newcap,
                    'permission' => $grant->permission,
                    'timemodified' => time(),
                    'modifierid' => 0,
                ]);
            }
        }
        $DB->delete_records('role_capabilities', ['capability' => $oldcap->capability]);
    }

    // Limpiar las capabilities del componente anterior una vez migradas.
    $DB->delete_records('capabilities', ['component' => $oldcomponent]);

    return true;
}
