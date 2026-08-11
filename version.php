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
 * Version details.
 *
 * @package   local_downloadcentercustom
 * @author    Simeon Naydenov
 * @copyright 2020 Academic Moodle Cooperation {@link http://www.academic-moodle-cooperation.org}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @modified  2026 José Luis Rodriguez Escobedo - Universidad Tecnológica de Jalisco
 *            Adaptación a Centro de descargas de evidencias con filtrado por grupos,
 *            capacidades por rol (downloadMaterials/downloadAssingments) y generación
 *            de ZIP con instrucciones HTML y estructura de materiales a nivel curso.
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2025072101;
$plugin->requires  = 2025100600;
$plugin->component = 'local_downloadcentercustom';
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = "v5.1.1";
