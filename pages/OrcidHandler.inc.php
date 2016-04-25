<?php

/**
 * @file plugins/generic/orcidProfile/OrcidHandler.inc.php
 *
 * Copyright (c) 2015 University of Pittsburgh
 * Distributed under the GNU GPL v2 or later. For full terms see the file docs/COPYING.
 *
 * @class OrcidHandler
 * @ingroup plugins_generic_orcidprofile
 *
 * @brief Pass off internal ORCID API requests to ORCID
 */

import('classes.handler.Handler');

class OrcidHandler extends Handler {
	/**
	 * Authorize handler
	 * @param $args array
	 * @param $request Request
	 */
	function authorize($args, &$request) {
		//die("RECEIVED AUTHORIZATION CODE: " . Request::getUserVar('code'));
		define('OAUTH_TOKEN_URL', 'https://pub.orcid.org/oauth/token'); // public

		//$router = Request::getRouter();
                $journal = Request::getJournal(); //& $router->getContext($request);
                $op = Request::getRequestedOp();
                $plugin =& PluginRegistry::getPlugin('generic', 'orcidprofileplugin');

		// fetch the access token
		$curl = curl_init();
		curl_setopt_array($curl, array(
		  CURLOPT_URL => OAUTH_TOKEN_URL,
		  CURLOPT_RETURNTRANSFER => true,
		  CURLOPT_HTTPHEADER => array('Accept: application/json'),
		  CURLOPT_POST => true,
		  CURLOPT_POSTFIELDS => http_build_query(array(
		    'code' => Request::getUserVar('code'),
		    'grant_type' => 'authorization_code',
		    'client_id' => $plugin->getSetting($journal->getId(), 'orcidClientId'),
		    'client_secret' => $plugin->getSetting($journal->getId(), 'orcidClientSecret')
		  ))
		));
		$result = curl_exec($curl);
		//$info = curl_getinfo($curl);
		$response = json_decode($result, true);
		// ORCID = $response['orcid']
		print_r($response);	
	}

	/**
	 * Index handler
	 * @param $args array
	 * @param $request Request
	 */
	function index($args, &$request) {
		$router =& $request->getRouter();
		$journal =& $router->getContext($request);
		$op = Request::getRequestedOp($request);
		$plugin =& PluginRegistry::getPlugin('generic', 'OrcidProfilePlugin');
		$params = $request->getQueryArray();
		$json = array();
		$orcid = '';
		if (isset($params['q'])) {
			if (strpos($params['q'], '@') !== FALSE) {
				// email query
				$curl = curl_init();
				curl_setopt_array($curl, array(
					CURLOPT_RETURNTRANSFER => 1,
					CURLOPT_URL => 'http://pub.orcid.org/v1.2/search/orcid-bio/?q=email:'.$params['q'],
					CURLOPT_HTTPHEADER => array('Accept: application/json'),
				));
				$result = curl_exec($curl);
				$info = curl_getinfo($curl);
				if ($info['http_code'] == 200) {
					$json = json_decode($result, true);
				}
				// extract ORCID from json
				if (isset($json['orcid-search-results']['orcid-search-result'][0]['orcid-profile']['orcid-identifier']['path'])) {
					$orcid = $json['orcid-search-results']['orcid-search-result'][0]['orcid-profile']['orcid-identifier']['path'];
				}
			} else {
				// orcid query
				import('lib.pkp.classes.validation.ValidatorORCID');
				$validator = new ValidatorORCID();
				if ($validator->isValid($params['q'])) {
					$orcid = substr($params['q'], -19);
				} else {
					$orcid = $params['q'];
				}
			}
			if ($orcid) {
				$curl = curl_init();
				curl_setopt_array($curl, array(
					CURLOPT_RETURNTRANSFER => 1,
					CURLOPT_URL => 'http://pub.orcid.org/v1.2/'.$orcid.'/orcid-profile',
					CURLOPT_HTTPHEADER => array('Accept: application/json'),
				));
				$result = curl_exec($curl);
				$info = curl_getinfo($curl);
				if ($info['http_code'] == 200) {
					$json = json_decode($result, true);
				}

			}
		} else {
		}
		// TODO: switch back to JSON content-type
		header('Content-type: application/json');
		$return = array();
		if ($orcid) {
			$return['orcid'] = 'http://orcid.org/'.$orcid;
		}
		if (isset($json['orcid-profile']['orcid-bio']['personal-details']['family-name']['value'])) {
			$return['lastName'] = $json['orcid-profile']['orcid-bio']['personal-details']['family-name']['value'];
		}
		if (isset($json['orcid-profile']['orcid-bio']['personal-details']['given-names']['value'])) {
			$return['firstName'] = $json['orcid-profile']['orcid-bio']['personal-details']['given-names']['value'];
		}
		$return['affiliation'] = '';
		if (isset($json['orcid-profile']['orcid-activities']['affiliations']['affiliation'][0]['organization']['name'])) {
			$return['affiliation'] .= $json['orcid-profile']['orcid-activities']['affiliations']['affiliation'][0]['organization']['name']."\n";
		}
		if (isset($json['orcid-profile']['orcid-activities']['affiliations']['affiliation'][0]['department-name'])) {
			$return['affiliation'] .= $json['orcid-profile']['orcid-activities']['affiliations']['affiliation'][0]['organization']['address']['city']."\n";
		}
		if (isset($json['orcid-profile']['orcid-activities']['affiliations']['affiliation'][0]['organization']['address']['region'])) {
			$return['affiliation'] .= $json['orcid-profile']['orcid-activities']['affiliations']['affiliation'][0]['organization']['address']['region']."\n";
		}
		if (!$return['affiliation']) {
			unset($return['affiliation']);
		}
		if (isset($json['orcid-profile']['orcid-bio']['contact-details']['email'][0]['value'])) {
			$return['email'] = $json['orcid-profile']['orcid-bio']['contact-details']['email'][0]['value'];
		}
		if (isset($json['orcid-profile']['orcid-bio']['researcher-urls']['researcher-url'][0]['url']['value'])) {
			$return['userUrl'] = $json['orcid-profile']['orcid-bio']['researcher-urls']['researcher-url'][0]['url']['value'];
		}
		if (isset($json['orcid-profile']['orcid-activities']['affiliations']['affiliation']['organization']['address']['country'])) {
			$return['country'] = $json['orcid-profile']['orcid-activities']['affiliations']['affiliation']['organization']['address']['country'];
		}
		// TODO: this should be localized, and may need special treatment because of TinyMCE
		if (isset($json['orcid-profile']['orcid-bio']['biography']['value'])) {
			$return['biography'] = $json['orcid-profile']['orcid-bio']['biography']['value'];
		}
		print json_encode($return);
	}

}

?>
