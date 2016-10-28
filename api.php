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

require_once dirname(__FILE__) . "/../php/core.inc.php";

@session_start();
try {
	$IP = getClientIp();

	$jsonrpc = new jsonrpc(init('request'));

	if (!mySqlIsHere()) {
		throw new Exception('Mysql non lancé', -32001);
	}

	if ($jsonrpc->getJsonrpc() != '2.0') {
		throw new Exception('Requete invalide, non JSON RPC 2.0. Valeur envoyé : ' . print_r(init('request'), true), -32001);
	}
	$params = $jsonrpc->getParams();

	$user = null;
	if (isset($params['username']) && trim($params['username']) != '') {
		if (!isset($params['password_type']) || $params['password_type'] != 'sha1') {
			$params['password'] = sha1($params['password']);
		}
		$user = user::connect($params['username'], $params['password']);
		if (!is_object($user)) {
			$user = null;
		} else {
			if ($user->getOptions('cgu::user::signed') != 1) {
				throw new Exception('Vous devez accepter les conditions générales de vente avant de pouvoir utiliser le market. Veuillez aller sur https://www.jeedom.com/market pour les signer', -32027);
			}
		}
	}

	$_SESSION['user'] = $user;

	if (!user::marketEnable()) {
		throw new Exception(__('Le market est désactivé, merci de revenir plus tard', __FILE__));
	}

	$register = null;

	if (isset($params['hwkey']) && trim($params['hwkey']) != '') {
		$register = register::byHwkey($params['hwkey']);
		if (is_object($register) && $user !== null && $register->getUser_id() != $user->getId() && $register->getUser_id() != -1) {
			$register = null;
			$jsonrpc->setAdditionalParameter('register::hwkey_nok', 1);
		} else {
			if (!is_object($register)) {
				$register = new register();
				$register->setHwkey($params['hwkey']);
			}
			$register->setRemoteIp(getClientIp());
			if ($user !== null) {
				$register->setUser_id($user->getId());
			}
			if (isset($params['information']) && is_array($params['information'])) {
				foreach ($params['information'] as $key => $value) {
					$register->setInformation($key, $value);
				}
			}
			if (isset($params['localIp'])) {
				$register->setLocalIp($params['localIp']);
			}
			if (isset($params['jeedom_name'])) {
				$register->setName($params['jeedom_name']);
			}
			if (isset($params['jeedomversion'])) {
				$register->setJeedom_version($params['jeedomversion']);
			} else {
				$register->setJeedom_version('');
			}
			if (isset($params['plugin_install_list']) && is_array($params['plugin_install_list'])) {
				foreach ($params['plugin_install_list'] as $logicalId) {
					try {
						$market = market::byLogicalId($logicalId);
						if (is_object($market)) {
							$register->addMarket($market->getId());
						}
					} catch (Exception $e) {

					}
				}
			}
			$register->save();
		}
	}
	if ($register != null && $user != null) {
		$jsonrpc->setAdditionalParameter('market::allowBeta', $user->getRights('betaTesteur'));
		if ($user->getLicenceInfo('allowdns', false)) {
			$jsonrpc->setAdditionalParameter('register::ngrokAddr', $register->getDnsAddr());
			$jsonrpc->setAdditionalParameter('register::ngrokToken', $register->getDnsToken());
			$jsonrpc->setAdditionalParameter('register::dnsToken', $register->getDnsToken());
			$jsonrpc->setAdditionalParameter('register::dnsNumber', $register->getDnsNumber());
		}
		if ($register->getJeedomUrl() != '') {
			$jsonrpc->setAdditionalParameter('jeedom::url', $register->getJeedomUrl());
		}
	}

	if (!isset($params['jeedomversion'])) {
		$params['jeedomversion'] = '';
	}

	if ($user !== null && count(register::byUserId($user->getId())) > $user->getLicenceInfo('hwlimit', 2)) {
		throw new Exception('Vous avez un trop grand nombre de systeme jeedom déclaré, veuillez en réduire le nombre en allant sur votre page profils du market et en supprimant des jeedoms, n\'oubliez pas de sauvegarder');
	}

	/*     * ***********************Ping********************************* */
	if ($jsonrpc->getMethod() == 'ping') {
		$jsonrpc->makeSuccess('pong');
	}
	/*     * ***********************Stats********************************* */
	if ($jsonrpc->getMethod() == 'stats::nbUsers') {
		$jsonrpc->makeSuccess(count(user::all()));
	}

	if ($jsonrpc->getMethod() == 'stats::nbPlugin') {
		$jsonrpc->makeSuccess(count(market::byType('plugin')));
	}

	if ($jsonrpc->getMethod() == 'stats::nbPluginDownload') {
		$jsonrpc->makeSuccess(market::nbDownloadByType('plugin'));
	}

	/*     * *******************User************************** */
	if ($jsonrpc->getMethod() == 'user::sendMessage') {
		if ($user == null) {
			throw new Exception('[user::sendMessage] Utilisateur non authentifié');
		}
		sendMail(array(
			'a' => $user->getMail(),
			'username' => $user->getSurname(),
			'object' => $params['title'],
			'message' => $params['message'],
		));
		$jsonrpc->makeSuccess('ok');
	}

	/*     * ************************Register************************** */
	if ($jsonrpc->getMethod() == 'register') {
		$jsonrpc->makeSuccess('toto');
	}

	/*     * ************************Purchase*************************** */
	if ($jsonrpc->getMethod() == 'purchase::getInfo') {
		$return = array();
		if ($user != null) {
			$return['user_id'] = $user->getId();
			$return['paypal::url'] = config::byKey('paypal::url');
			$return['paypal::marchandMail'] = config::byKey('paypal::marchandMail');
		}
		$jsonrpc->makeSuccess($return);
	}

	/*     * ************************jeedom*************************** */
	if ($jsonrpc->getMethod() == 'jeedom::getCurrentVersion') {
		if (!isset($params['branch'])) {
			$params['branch'] = 'stable';
		}
		$jsonrpc->makeSuccess(getJeedomCurrentVersion($params['branch']));
	}

	if ($jsonrpc->getMethod() == 'jeedom::getList') {
		if ($user == null) {
			throw new Exception('[jeedom::getList] Utilisateur non authentifié.');
		}
		$jsonrpc->makeSuccess(utils::o2a(register::byUserId($user->getId())));
	}

	/*     * ************************Market*************************** */

	if ($jsonrpc->getMethod() == 'market::test') {
		if ($user == null) {
			throw new Exception('[market::test] Utilisateur non authentifié (mot de passe ou nom d\'utilisateur invalide).');
		}
		$jsonrpc->makeSuccess(array('ok'));
	}

	if ($jsonrpc->getMethod() == 'market::byStatusAndType') {
		$return = array();
		foreach (market::byStatusAndType($params['status'], $params['type']) as $market) {
			$info_market = $market->toArray($user);
			$return[] = $info_market;
		}
		$jsonrpc->makeSuccess($return);
	}

	if ($jsonrpc->getMethod() == 'market::byStatus') {
		$return = array();
		foreach (market::byStatus($params['status']) as $market) {
			$info_market = $market->toArray($user);
			$return[] = $info_market;
		}
		$jsonrpc->makeSuccess($return);
	}

	if ($jsonrpc->getMethod() == 'market::byFilter') {
		$return = array();
		foreach (market::byFilter($params) as $market) {
			if (!$market->view($user, false, $params['jeedomversion'])) {
				continue;
			}
			$info_market = $market->toArray($user);
			$return[] = $info_market;
		}
		$jsonrpc->makeSuccess($return);
	}

	if ($jsonrpc->getMethod() == 'market::byId') {
		if (isset($params['id'])) {
			$market = market::byId($params['id']);
			if (!is_object($market)) {
				throw new Exception('[market::byId] Market ID non valide.', -32025);
			}
			$info_market = $market->toArray($user);
			$jsonrpc->makeSuccess($info_market);
		} else {
			$return = array();
			foreach ($params as $param) {
				if (is_array($param)) {
					continue;
				}
				$market = market::byId($params['id']);
				if (!is_object($market)) {
					throw new Exception('[market::byId] Market ID non valide.', -32025);
				}
				$info_market = $market->toArray($user);
				$return[$market->getId()] = $info_market;
			}
			$jsonrpc->makeSuccess($return);
		}
	}

	if ($jsonrpc->getMethod() == 'market::searchZwaveModuleConf') {
		$return = array();
		foreach (market::searchZwaveModuleConf($params['manufacturerId'], $params['manufacturerProductType'], $params['manufacturerProductId']) as $market) {
			$info_market = $market->toArray($user);
			$return[] = $info_market;
		}
		$jsonrpc->makeSuccess($return);
	}

	if ($jsonrpc->getMethod() == 'market::byLogicalIdAndType') {
		if (isset($params['logicalId']) && !isset($params['params'])) {
			if (!isset($params['logicalId']) || !isset($params['type'])) {
				continue;
			}
			$market = market::byLogicalIdAndType($params['logicalId'], $params['type']);
			if (!is_object($market)) {
				throw new Exception('[market::byLogicalId] Market logical id non valide.', -32026);
			}
			$info_market = $market->toArray($user);
			$jsonrpc->makeSuccess($info_market);
		} else {
			$return = array();
			foreach ($params as $param) {
				if (!isset($param['logicalId'])) {
					continue;
				}
				$market = market::byLogicalIdAndType($param['logicalId'], $param['type']);
				if (!is_object($market)) {
					continue;
				}
				$info_market = $market->toArray($user);
				$return[$market->getType() . $market->getLogicalId()] = $info_market;
			}
			$jsonrpc->makeSuccess($return);
		}
	}

	if ($jsonrpc->getMethod() == 'market::byLogicalId') {
		if (isset($params['logicalId'])) {
			$market = market::byLogicalId($params['logicalId']);
			if (!is_object($market)) {
				throw new Exception('[market::byLogicalId] Market logical id non valide.', -32026);
			}
			$info_market = $market->toArray($user);
			$jsonrpc->makeSuccess($info_market);
		} else {
			$return = array();
			foreach ($params as $param) {
				if (is_array($param)) {
					continue;
				}
				$market = market::byLogicalId($param);
				if (!is_object($market)) {
					continue;
				}
				$info_market = $market->toArray($user);
				$return[$market->getLogicalId()] = $info_market;
			}
			$jsonrpc->makeSuccess($return);
		}
	}

	if ($jsonrpc->getMethod() == 'market::changelog') {
		if (isset($params['logicalId'])) {
			$market = market::byLogicalId($params['logicalId']);
			if (!is_object($market)) {
				throw new Exception('[market::byLogicalId] Market logical id non valide.', -32026);
			}
			$market->limitChangelog($user);
			$changelog = json_encode($market->getChangeLog(), true);
			foreach ($changelog as $change) {
				if (strtotime($change['date']) > strtotime($params['datetime'])) {
					$return[] = $change;
				}
			}
			$jsonrpc->makeSuccess($return);
		} else {
			$return = array();
			foreach ($params as $param) {
				if (!isset($param['logicalId'])) {
					continue;
				}
				$market = market::byLogicalId($param['logicalId']);
				if (!is_object($market)) {
					continue;
				}
				$return[$market->getLogicalId()] = array();
				$market->limitChangelog($user);
				$changelog = json_decode($market->getChangeLog(), true);
				if (is_array($changelog)) {
					foreach ($changelog as $change) {
						if (strtotime($change['date']) > strtotime($param['datetime'])) {
							$return[$market->getLogicalId()][] = $change;
						}
					}
				}
			}
			$jsonrpc->makeSuccess($return);
		}
	}

	if ($jsonrpc->getMethod() == 'market::byAuthor') {
		if ($user == null) {
			throw new Exception('[market::byAuthor] Utilisateur non authentifié.');
		}
		$return = array();
		foreach (market::byAuthor($user->getId()) as $market) {
			$info_market = $market->toArray($user);
			$return[] = $info_market;
		}
		$jsonrpc->makeSuccess($return);
	}

	if ($jsonrpc->getMethod() == 'market::distinctCategorie') {
		$jsonrpc->makeSuccess(market::distinctCategorie($params['type']));
	}

	if ($jsonrpc->getMethod() == 'market::save') {
		if ($user == null) {
			throw new Exception('[market::save] Utilisateur non authentifié.');
		}
		if ($user->getOptions('cgu::dev::signed') != 1) {
			throw new Exception('[market::save] Vous devez d\'abord accepter les CGU de développeur avant de pouvoir envoyer un objet. Pour se faire, allez sur la page de votre profil sur le market puis cliquez sur voir en face des CGU de développeur');
		}
		$market = market::byId($params['id']);
		if (!is_object($market)) {
			$market = market::byLogicalId($params['logicalId']);
			if (!is_object($market)) {
				unset($params['id']);
				$market = new market();
				$market->setUser_id($user->getId());
			}
		}

		if ($user->getId() != $market->getUser_id() && $user->getRights('admin') != 1 && $user->getRights('editAllMarket') != 1) {
			throw new Exception('[market::save] Vous n\'êtes pas autorisé à modifier cette objet.');
		}
		if (isset($params['release']) && $params['release'] == 1 && $market->getStatus('beta') == 0) {
			throw new Exception('[market::save] Vous ne pouvez envoyer une release sans jamais avoir fait de beta');
		}
		if (isset($params['rating'])) {
			unset($params['rating']);
		}
		if (isset($params['datetime'])) {
			unset($params['datetime']);
		}
		if (isset($params['user_id'])) {
			unset($params['user_id']);
		}
		if (!$user->getRights('admin')) {
			unset($params['certification']);
			unset($params['link']['domadoo']);
		}
		utils::a2o($market, $params);
		if ($market->getType() != 'plugin') {
			$market->setCost(0);
		}
		if ($market->getUser_id() == '' || !is_numeric($market->getUser_id())) {
			$market->setUser_id($user->getId());
		}
		if (isset($params['change']) && $params['change'] != '') {
			$changelog = $market->getChangeLog();
			if (!is_json($changelog)) {
				$changelog = array();
			} else {
				$changelog = json_decode($changelog, true);
			}
			$changelog[] = array(
				'version' => $params['version'],
				'date' => date('Y-m-d H:i:s'),
				'change' => $params['change'],
			);
			$market->setChangeLog(json_encode($changelog));
		}
		$market->setUpdateBy($user->getPseudo());
		$market->save();
		if (isset($params['release']) && $params['release'] == 1) {
			$market->uploadFile($_FILES['file'], 'release');
			$market->toRelease($user, false);
		} else {
			$market->uploadFile($_FILES['file']);
		}
		$jsonrpc->makeSuccess('ok');
	}

	if ($jsonrpc->getMethod() == 'market::setRating') {
		if ($user == null) {
			throw new Exception('[market::setRating] ' . __('Utilisateur non authentifié.', __FILE__));
		}
		$market = market::byId($params['id']);
		if (!is_object($market)) {
			throw new Exception('[market::setRating] ' . __('Market ID non valide.', __FILE__));
		}
		$market->setRating($user->getId(), $params['rating']);
		$jsonrpc->makeSuccess('ok');
	}

	if ($jsonrpc->getMethod() == 'market::getRating') {
		$market = market::byId($params['id']);
		if (!is_object($market)) {
			throw new Exception('[market::getRating] ' . __('Market ID non valide.', __FILE__));
		}
		$return = array();
		if ($user != null) {
			$return['user'] = $market->getRating($user->getId());
		}
		$return['average'] = $market->getRating();
		$jsonrpc->makeSuccess($return);
	}

	/*     * ***************************** backup ****************************************** */

	if ($jsonrpc->getMethod() == 'backup::upload') {
		if ($user == null) {
			throw new Exception('[backup::upload] ' . __('Utilisateur non authentifié.', __FILE__));
		}
		backup::upload($_FILES['file'], $user, $register);
		$jsonrpc->makeSuccess('ok');
	}

	if ($jsonrpc->getMethod() == 'backup::liste') {
		if ($user == null) {
			throw new Exception('[backup::liste] ' . __('Utilisateur non authentifié.', __FILE__));
		}
		$jsonrpc->makeSuccess(backup::liste($user));
	}

	/*     * ***************************** Tickets ****************************************** */
	if ($jsonrpc->getMethod() == 'ticket::save') {
		openTicket($params['ticket'], $user);
		$jsonrpc->makeSuccess('ok');
	}

	/*     * ***************************** PHONEMARKET ****************************************** */

	if ($jsonrpc->getMethod() == 'phonemarket::sms') {
		if ($user == null) {
			throw new Exception('[phonemarket::sms] ' . __('Utilisateur non authentifié.', __FILE__));
		}
		if ($user->getSmsleft() < 1 && $user->getRights('admin') != 1) {
			throw new Exception('[phonemarket::sms] ' . __('Vous n\'avez plus de quota SMS.', __FILE__));
		}
		if (!isset($params['number']) || $params['number'] == '') {
			throw new Exception('[phonemarket::sms] ' . __('Le numéro ne peut etre vide.', __FILE__));
		}
		if (!isset($params['message']) || $params['message'] == '') {
			throw new Exception('[phonemarket::sms] ' . __('Le message ne peut etre vide.', __FILE__));
		}
		$caracteres = array(
			'À' => 'a', 'Á' => 'a', 'Â' => 'a', 'Ä' => 'a', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', '@' => 'a',
			'È' => 'e', 'É' => 'e', 'Ê' => 'e', 'Ë' => 'e', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', '€' => 'e',
			'Ì' => 'i', 'Í' => 'i', 'Î' => 'i', 'Ï' => 'i', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
			'Ò' => 'o', 'Ó' => 'o', 'Ô' => 'o', 'Ö' => 'o', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o',
			'Ù' => 'u', 'Ú' => 'u', 'Û' => 'u', 'Ü' => 'u', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'µ' => 'u',
			'Œ' => 'oe', 'œ' => 'oe',
			'$' => 's');
		$params['message'] = preg_replace('#[^A-Za-z0-9 \n\.\'=\*:]+#', '', strtr($params['message'], $caracteres));
		if (strlen($params['message']) > 140) {
			$params['message'] = substr($params['message'], 0, 140);
		}
		if (strlen($params['number']) == 10) {
			$params['number'] = '+33' . substr($params['number'], 1);
		}
		$client = new Services_Twilio(config::byKey('twilio::account_sid'), config::byKey('twilio::auth_token'));
		$client->account->messages->create(array(
			"From" => config::byKey('twilio::from_number'),
			"To" => $params['number'],
			"Body" => $params['message'],
		));
		$user->setSmsleft($user->getSmsleft() - config::byKey('phonemarket::smscredit', 'core', 1));
		$user->save();
		if ($user->getSmsleft() < 5 && $user->getRights('admin') != 1 && $user->getRights('freesms') != 1) {
			sendMail(array(
				'a' => $user->getMail(),
				'username' => $user->getSurname(),
				'object' => '[SMS/APPELS crédit] Crédit SMS/APPELS faible',
				'message' => 'Bonjour<br> Votre crédit SMS/APPELS est faible : ' . $user->getSmsleft() . ', pensez à recharger à partir de votre page profil sur le market sinon vous ne pourrez plus envoyer de SMS ou passer des appels',
			));
		}
		$jsonrpc->makeSuccess('ok');
	}

	if ($jsonrpc->getMethod() == 'phonemarket::call') {
		if ($user == null) {
			throw new Exception('[phonemarket::call] ' . __('Utilisateur non authentifié.', __FILE__));
		}
		if ($user->getSmsleft() < 2 && $user->getRights('admin') != 1) {
			throw new Exception('[phonemarket::call] ' . __('Vous n\'avez plus pas assez de crédits.', __FILE__));
		}
		if (!isset($params['number']) || $params['number'] == '') {
			throw new Exception('[phonemarket::call] ' . __('Le numéro ne peut etre vide.', __FILE__));
		}
		if (!isset($params['message']) || $params['message'] == '') {
			throw new Exception('[phonemarket::call] ' . __('Le message ne peut etre vide.', __FILE__));
		}
		if (strlen($params['message']) > 450) {
			$params['message'] = substr($params['message'], 0, 450);
		}
		if (strlen($params['number']) == 10) {
			$params['number'] = '+33' . substr($params['number'], 1);
		}
		if (in_array($params['number'], array('+3317', '+33112', '+3318', '+3315'))) {
			throw new Exception('[phonemarket::call]' . __('Vous ne pouvez appeller ce numéro.', __FILE__));
		}
		$uid = 'phonemarket' . config::genKey(50);
		while (config::byKey($uid) != '') {
			$uid = 'phonemarket' . config::genKey(50);
		}
		config::save($uid, json_encode($params));
		$client = new Services_Twilio(config::byKey('twilio::account_sid'), config::byKey('twilio::auth_token'));

		$call = $client->account->calls->create(
			config::byKey('twilio::from_number'),
			$params['number'],
			config::byKey('callbackurl') . '/core/php/twilioInboundXml.php?uid=' . $uid
		);
		$user->setSmsleft($user->getSmsleft() - config::byKey('phonemarket::callcredit', 'core', 2));
		$user->save();

		if ($user->getSmsleft() < 5 && $user->getRights('admin') != 1 && $user->getRights('freesms') != 1) {
			sendMail(array(
				'a' => $user->getMail(),
				'username' => $user->getSurname(),
				'object' => '[SMS/APPELS crédit] Crédit SMS/APPELS faible',
				'message' => 'Bonjour<br> Votre crédit SMS/APPELS est faible : ' . $user->getSmsleft() . ', pensez à recharger à partir de votre page profil sur le market sinon vous ne pourrez plus envoyer de SMS ou passer des appels',
			));
		}
		$jsonrpc->makeSuccess('ok');
	}

	/*     * ************************************************************************ */

	throw new Exception('Methode non trouvée : ' . $jsonrpc->getMethod(), -32601);
	/*     * *********Catch exeption*************** */
} catch (Exception $e) {
	$message = $e->getMessage();
	$jsonrpc = new jsonrpc(init('request'));
	$errorCode = (is_numeric($e->getCode())) ? $e->getCode() : -32699;
	if(strpos($message,'Market logical id non valide') === false){
		log::add('api', 'info', 'Return error : '. $message .' => ' . print_r($jsonrpc, true));
	}
	$jsonrpc->makeError($errorCode, $message);
}
?>
