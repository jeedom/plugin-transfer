<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* * ***************************Includes********************************* */
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

class transfert extends eqLogic {
	/*     * *************************Attributs****************************** */

	/*     * ***********************Methode static*************************** */

	public static function sortByDatetime($a, $b) {
		if (strtotime($a['datetime']) == strtotime($b['datetime'])) {
			return 0;
		}
		return (strtotime($a['datetime']) < strtotime($b['datetime'])) ? -1 : 1;
	}

	/*     * *********************Méthodes d'instance************************* */

	public function postSave() {
		$cmd = $this->getCmd(null, 'send');
		if (!is_object($cmd)) {
			$cmd = new transfertCmd();
			$cmd->setLogicalId('send');
			$cmd->setIsVisible(1);
			$cmd->setName(__('Envoyer', __FILE__));
		}
		$cmd->setType('action');
		$cmd->setSubType('message');
		$cmd->setEqLogic_id($this->getId());
		$cmd->save();

		$cmd = $this->getCmd(null, 'clean');
		if (!is_object($cmd)) {
			$cmd = new transfertCmd();
			$cmd->setLogicalId('clean');
			$cmd->setIsVisible(1);
			$cmd->setName(__('Nettoyer', __FILE__));
		}
		$cmd->setType('action');
		$cmd->setSubType('slider');
		$cmd->setEqLogic_id($this->getId());
		$cmd->save();
	}

	public function samba_put($_files = array()) {
		$smb_file = array();
		$cmd = '';
		foreach ($_files as $file) {
			$info = pathinfo($file);
			$filename = str_replace(array('_', ':'), array('-', '-'), $info['basename']);
			$cmd .= 'mv ' . $file . ' /tmp/' . $filename . ';';
			$smb_file[] = $filename;
		}
		$cmd .= 'cd /tmp;';
		$cmd .= 'sudo smbclient ' . $this->getConfiguration('samba::share') . ' -U ' . $this->getConfiguration('samba::username') . '%' . $this->getConfiguration('samba::password') . ' -I ' . $this->getConfiguration('samba::ip');
		$cmd .= ' -c "cd ' . $this->getConfiguration('samba::path') . ';';
		foreach ($smb_file as $file) {
			$cmd .= 'put ' . $file . ';';
		}
		$cmd .= '" >> /dev/null 2>&1';
		log::add('transfert', 'debug', $cmd);
		try {
			com_shell::execute($cmd);
		} catch (Exception $e) {
			log::add('transfert', 'error', __('Erreur lors du transfert samba de : ', __FILE__) . $file . ' : ' . log::exception($e));
		}
	}

	public function samba_clean($_limit = 3) {
		$base_cmd = 'sudo smbclient ' . $this->getConfiguration('samba::share') . ' -U ' . $this->getConfiguration('samba::username') . '%' . $this->getConfiguration('samba::password') . ' -I ' . $this->getConfiguration('samba::ip');
		$cmd = $base_cmd . ' -c "cd ' . $this->getConfiguration('samba::path') . ';ls"';
		log::add('transfert', 'debug', $cmd);
		$result = explode("\n", com_shell::execute($cmd));
		$return = array();
		for ($i = 2; $i < count($result) - 2; $i++) {
			$line = array();
			foreach (explode(" ", $result[$i]) as $value) {
				if (trim($value) == '') {
					continue;
				}
				$line[] = $value;
			}
			$file_info = array();
			$file_info['filename'] = $line[0];
			$file_info['size'] = $line[2];
			$file_info['datetime'] = date('Y-m-d H:i:s', strtotime($line[5] . ' ' . $line[4] . ' ' . $line[7] . ' ' . $line[6]));
			$return[] = $file_info;
		}
		usort($return, 'transfert::sortByDatetime');
		$timelimit = strtotime('-' . $_limit . ' days');
		$cmd = $base_cmd . ' -c "cd ' . $this->getConfiguration('samba::path') . ';';
		$find_file = false;
		foreach (array_reverse($return) as $file) {
			if ($timelimit > strtotime($file['datetime'])) {
				$find_file = true;
				$cmd .= 'del ' . $file['filename'] . ';';
			}
		}
		if ($find_file) {
			$cmd .= '" >> /dev/null 2>&1';
			log::add('transfert', 'debug', $cmd);
			com_shell::execute($cmd);
		}
	}

	/*     * **********************Getteur Setteur*************************** */
}

class transfertCmd extends cmd {
	/*     * *************************Attributs****************************** */

	/*     * ***********************Methode static*************************** */

	/*     * *********************Methode d'instance************************* */

	public function dontRemoveCmd() {
		return true;
	}

	public function execute($_options = array()) {
		$eqLogic = $this->getEqLogic();
		if ($this->getLogicalId() == 'send') {
			if (!isset($_options['files']) || !is_array($_options['files']) || count($_options['files']) == 0) {
				return;
			}
			switch ($eqLogic->getConfiguration('service')) {
				case 'samba':
					$eqLogic->samba_put($_options['files']);
					break;
			}
		}
		if ($this->getLogicalId() == 'clean') {
			if (!isset($_options['slider']) || $_options['slider'] < 1) {
				return;
			}
			switch ($eqLogic->getConfiguration('service')) {
				case 'samba':
					$eqLogic->samba_clean($_options['slider']);
					break;
			}
		}
	}

	/*     * **********************Getteur Setteur*************************** */
}

?>
