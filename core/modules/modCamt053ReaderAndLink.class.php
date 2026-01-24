<?php
/* Copyright (C) 2004-2018  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2018-2019  Nicolas ZABOURI         <info@inovea-conseil.com>
 * Copyright (C) 2019-2020  Frédéric France         <frederic.france@netlogic.fr>
 * Copyright (C) 2024 SuperAdmin
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * 	\defgroup   camt053readerandlink     Module Camt053ReaderAndLink
 *  \brief      Camt053ReaderAndLink module descriptor.
 *
 *  \file       htdocs/camt053readerandlink/core/modules/modCamt053ReaderAndLink.class.php
 *  \ingroup    camt053readerandlink
 *  \brief      Description and activation file for module Camt053ReaderAndLink
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 *  Description and activation class for module Camt053ReaderAndLink
 */
class modCamt053ReaderAndLink extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs, $conf;
		$this->db = $db;

		// Id for module (must be unique)
		$this->numero = 550000;

		$this->rights_class = 'camt053readerandlink';
		$this->family = 'financial';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'Ability to read a camt.053 file and link to bank statements';
		$this->descriptionlong = "Camt053ReaderAndLinkDescription";

		$this->editor_name = 'Slordef';
		$this->editor_url = '';
		$this->version = '2.0.1';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'fa-building-columns';

		// Module features
		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'printing' => 0,
			'theme' => 0,
			'css' => array('/camt053readerandlink/css/camt053readerandlink.css'),
			'js' => array('/camt053readerandlink/js/camt053readerandlink.js.php'),
			'hooks' => array(),
			'moduleforexternal' => 0,
		);

		// Data directories
		$this->dirs = array("/camt053readerandlink/temp");

		// Config pages
		$this->config_page_url = array("setup.php@camt053readerandlink");

		// Dependencies
		$this->hidden = false;
		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();

		// Language files
		$this->langfiles = array("camt053readerandlink@camt053readerandlink");

		// Prerequisites
		$this->phpmin = array(7, 0);
		$this->need_dolibarr_version = array(11, -3);
		$this->need_javascript_ajax = 0;

		// Activation warnings
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		// Constants
		$this->const = array();

		if (!isModEnabled("camt053readerandlink")) {
			$conf->camt053readerandlink = new stdClass();
			$conf->camt053readerandlink->enabled = 0;
		}

		// Tabs
		$this->tabs = array();

		// Dictionaries
		$this->dictionaries = array();

		// Boxes/Widgets
		$this->boxes = array();

		// Cronjobs (List of cron jobs entries to add when module is enabled)
		$this->cronjobs = array();

		// Permissions provided by this module
		$this->rights = array();

		// Main menu entries
		$this->menu = array();
		$this->menu[0] = array(
			'fk_menu'=>'fk_mainmenu=bank,fk_leftmenu=bank', // '' if this is a top menu. For left menu, use 'fk_mainmenu=xxx' or 'fk_mainmenu=xxx,fk_leftmenu=yyy' where xxx is mainmenucode and yyy is a leftmenucode
			'type'=>'left', // This is a Top menu entry
			'titre'=>'ModuleCamt053ReaderAndLinkShortName',
			'mainmenu'=>'bank',
			'leftmenu'=>'',
			'url'=>'/camt053readerandlink/index.php',
			'langs'=>'camt053readerandlink@camt053readerandlink',
			'position'=>1000,
			'enabled'=>'isModEnabled("camt053readerandlink") && isModenabled("banque")',
			'perms'=>'$user->hasRight("banque", "lire")',
			'target'=>'',
			'user'=>0,
		);
	}

	/**
	 *  Function called when module is enabled.
	 *  The init function add constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
	 *  It also creates data directories
	 *
	 *  @param      string  $options    Options when enabling module ('', 'noboxes')
	 *  @return     int             	1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/camt053readerandlink/sql/');
		if ($result < 0) {
			return -1;
		}

		$this->remove($options);

		return $this->_init(array(), $options);
	}

	/**
	 *  Function called when module is disabled.
	 *  Remove from database constants, boxes and permissions from Dolibarr database.
	 *  Data directories are not deleted
	 *
	 *  @param      string	$options    Options when enabling module ('', 'noboxes')
	 *  @return     int                 1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
