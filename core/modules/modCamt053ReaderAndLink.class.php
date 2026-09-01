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
		$this->version = '2.2.0';
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
		// Everything this module does targets bank accounts and bank lines.
		$this->depends = array('modBanque');
		$this->requiredby = array();
		$this->conflictwith = array();

		// Language files
		$this->langfiles = array("camt053readerandlink@camt053readerandlink");

		// Prerequisites
		// PHP 7.4: the classes use `object` type hints (7.2) and the test matrix
		// starts at 7.4, which is the oldest version actually exercised.
		$this->phpmin = array(7, 4);
		// The code calls isModEnabled(), getDolGlobalString(), $user->hasRight()
		// and GETPOSTINT(), none of which exist in the versions previously
		// declared as supported. 17 is a conservative floor; the only version the
		// module is actually developed and run against is 24.
		$this->need_dolibarr_version = array(17, 0);
		// The SFTP auto-fetch needs ext-ssh2 (as Dolibarr core's own SFTP does).
		// Not listed as a hard requirement: the manual upload, which is the main
		// feature, works without it.
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
		// Disabled by default: enable it from the scheduled jobs page once at least
		// one SFTP account is configured.
		// Every 12 hours: the intraday camt.052 is fetched twice a day, and the
		// monthly camt.053 is picked up within 12 hours of its delivery.
		// This seeds new installations only. An existing job keeps the frequency
		// stored in its own row, which has to be changed on the scheduled jobs page.
		$this->cronjobs = array(
			0 => array(
				'label' => 'Fetch and reconcile CAMT.052/053 over SFTP',
				'jobtype' => 'method',
				'class' => '/camt053readerandlink/class/Camt053CronRunner.class.php',
				'objectname' => 'Camt053CronRunner',
				'method' => 'run',
				'parameters' => '',
				'comment' => 'Fetch CAMT.052 intraday reports and CAMT.053 statements from PostFinance MFTPF, auto-reconcile unique matches and report the monthly file to Zulip',
				'frequency' => 12,
				'unitfrequency' => 3600,
				'status' => 0,
				'test' => 'isModEnabled("camt053readerandlink")',
				'priority' => 50,
			),
		);

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
