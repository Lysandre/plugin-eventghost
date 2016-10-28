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
	log::add('proapi', 'info', init('request') . ' - IP :' . $IP);

	$jsonrpc = new jsonrpc(init('request'));

	if (!mySqlIsHere()) {
		throw new Exception('Mysql non lancé', -32001);
	}

	if ($jsonrpc->getJsonrpc() != '2.0') {
		throw new Exception('Requete invalide, non JSON RPC 2.0. Valeur envoyé : ' . print_r(init('request'), true), -32001);
	}
	$params = $jsonrpc->getParams();

	if (config::byKey('proapi') == '' || (config::byKey('proapi') != $params['apikey'])) {
		throw new Exception('Clef API invalide', -32001);
	}

	/*     * ***********************Ping********************************* */
	if ($jsonrpc->getMethod() == 'ping') {
		$jsonrpc->makeSuccess('pong');
	}

	/*     * ***********************User********************************* */

	if ($jsonrpc->getMethod() == 'user::get') {
		$user = user::byLogin($params['login']);
		if (!is_object($user)) {
			throw new Exception("User not found");
		}
		if ($user->getAccesskey() != $params['accesskey']) {
			throw new Exception("Access key invalid");
		}
		$jsonrpc->makeSuccess(utils::o2a($user));
	}
	
	if ($jsonrpc->getMethod() == 'user::getNbTickets') {
		$user = user::byLogin($params['login']);
		if (!is_object($user)) {
			throw new Exception("User not found");
		}
		$jsonrpc->makeSuccess($user->getNbticket());
	}
	
	if ($jsonrpc->getMethod() == 'user::setNbTickets') {
		$user = user::byLogin($params['login']);
		if (!is_object($user)) {
			throw new Exception("User not found");
		}
		if(isset($params['nbtickets'])){
			$user->setNbticket($params['nbtickets']);
			$user->save();
		}
		$jsonrpc->makeSuccess('ok');
	}

	/*     * ***********************Register********************************* */
	if ($jsonrpc->getMethod() == 'register::get') {
		$user = user::byLogin($params['login']);
		if (!is_object($user)) {
			throw new Exception("User not found");
		}
		if ($user->getAccesskey() != $params['accesskey']) {
			throw new Exception("Access key invalid");
		}
		$jsonrpc->makeSuccess(utils::o2a(register::byUserId($user->getId())));
	}
	
	if ($jsonrpc->getMethod() == 'register::setName') {
		$register = register::byId($params['registerId']);
		if (!is_object($register)) {
			throw new Exception("Register not found");
		}
		$register->setName($params['name']);
		$jsonrpc->makeSuccess('ok');
	}

	/*     * ************************************************************************ */

	throw new Exception('Methode non trouvée : ' . $jsonrpc->getMethod(), -32601);
	/*     * *********Catch exeption*************** */
} catch (Exception $e) {
	$message = $e->getMessage();
	$jsonrpc = new jsonrpc(init('request'));
	$errorCode = (is_numeric($e->getCode())) ? $e->getCode() : -32699;
	$jsonrpc->makeError($errorCode, $message);
}
?>
