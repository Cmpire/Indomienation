<?php

namespace WPLEClient;

use WPLEClient\Exceptions\LEOrderException;

/**
 * LetsEncrypt Order class, containing the functions and data associated with a specific LetsEncrypt order.
 *
 * PHP version 5.2.0
 *
 * MIT License
 *
 * Copyright (c) 2018 Youri van Weegberg
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * @author     Youri van Weegberg <youri@yourivw.nl>
 * @copyright  2018 Youri van Weegberg
 * @license    https://opensource.org/licenses/mit-license.php  MIT License
 * @link       https://github.com/yourivw/LEClient
 * @since      Class available since Release 1.0.0
 */
class LEOrder
{
  private $connector;

  private $basename;
  private $certificateKeys;
  private $orderURL;
  private $keyType;
  private $keySize;

  private $keysdir;

  public $status;
  public $expires;
  public $identifiers;
  private $authorizationURLs;
  public $authorizations;
  public $finalizeURL;
  public $certificateURL;

  private $log;


  const CHALLENGE_TYPE_HTTP = 'http-01';
  const CHALLENGE_TYPE_DNS = 'dns-01';

  /**
   * Initiates the LetsEncrypt Order class. If the base name is found in the $keysDir directory, the order data is requested. If no order was found locally, if the request is invalid or when there is a change in domain names, a new order is created.
   *
   * @param LEConnector	$connector			The LetsEncrypt Connector instance to use for HTTP requests.
   * @param int 			$log 				The level of logging. Defaults to no logging. LOG_OFF, LOG_STATUS, LOG_DEBUG accepted.
   * @param array 		$certificateKeys 	Array containing location of certificate keys files.
   * @param string 		$basename 			The base name for the order. Preferable the top domain (example.org). Will be the directory in which the keys are stored. Used for the CommonName in the certificate as well.
   * @param array 		$domains 			The array of strings containing the domain names on the certificate.
   * @param string 		$keyType 			Type of the key we want to use for certificate. Can be provided in ALGO-SIZE format (ex. rsa-4096 or ec-256) or simple "rsa" and "ec" (using default sizes)
   * @param string 		$notBefore 			A date string formatted like 0000-00-00T00:00:00Z (yyyy-mm-dd hh:mm:ss) at which the certificate becomes valid.
   * @param string 		$notAfter 			A date string formatted like 0000-00-00T00:00:00Z (yyyy-mm-dd hh:mm:ss) until which the certificate is valid.
   */
  public function __construct($connector, $log, $certificateKeys, $basename, $domains, $notBefore, $notAfter, $keyType = 'rsa-4096', $keydirectory = '')
  {
    $this->connector = $connector;
    $this->basename = $basename;
    $this->log = $log;
    $this->keysdir = $keydirectory;

    if ($keyType == 'rsa') {
      $this->keyType = 'rsa';
      $this->keySize = 4096;
    } elseif ($keyType == 'ec') {
      $this->keyType = 'ec';
      $this->keySize = 256;
    } else {
      preg_match_all('/^(rsa|ec)\-([0-9]{3,4})$/', $keyType, $keyTypeParts, PREG_SET_ORDER, 0);

      if (!empty($keyTypeParts)) {
        $this->keyType = $keyTypeParts[0][1];
        $this->keySize = intval($keyTypeParts[0][2]);
      } else throw LEOrderException::InvalidKeyTypeException($keyType);
    }

    if (preg_match('~(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z|^$)~', $notBefore) == false or preg_match('~(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z|^$)~', $notAfter) == false) {
      throw LEOrderException::InvalidArgumentException('notBefore and notAfter fields must be empty or be a string similar to 0000-00-00T00:00:00Z');
    }

    $this->certificateKeys = $certificateKeys;

    if (file_exists($this->certificateKeys['private_key']) and file_exists($this->certificateKeys['order']) and file_exists($this->certificateKeys['public_key'])) {
      $this->orderURL = file_get_contents($this->certificateKeys['order']);
      if (filter_var($this->orderURL, FILTER_VALIDATE_URL) !== false) {
        try {
          $sign = $this->connector->signRequestKid('', $this->connector->accountURL, $this->orderURL);
          $post = $this->connector->post($this->orderURL, $sign);
          LEFunctions::log('Order object is ' . json_encode($post['body']));

          if ($post['body']['status'] == "invalid") {
            throw LEOrderException::InvalidOrderStatusException();
          }

          $orderdomains = array_map(function ($ident) {
            return $ident['value'];
          }, $post['body']['identifiers']);
          $diff = array_merge(array_diff($orderdomains, $domains), array_diff($domains, $orderdomains));
          if (!empty($diff)) {
            foreach ($this->certificateKeys as $file) {
              if (is_file($file)) rename($file, $file . '.old');
            }
            if ($this->log instanceof \Psr\Log\LoggerInterface) {
              $this->log->info('Domains do not match order data. Renaming current files and creating new order.');
            } elseif ($this->log >= LEClient::LOG_STATUS) LEFunctions::log('Domains do not match order data. Renaming current files and creating new order.', 'function LEOrder __construct');
            $this->createOrder($domains, $notBefore, $notAfter, $keyType);
          } else {
            $this->status = $post['body']['status'];
            $this->expires = $post['body']['expires'];
            $this->identifiers = $post['body']['identifiers'];
            $this->authorizationURLs = $post['body']['authorizations'];
            $this->finalizeURL = $post['body']['finalize'];
            if (array_key_exists('certificate', $post['body'])) $this->certificateURL = $post['body']['certificate'];
            $this->updateAuthorizations();
          }
        } catch (\Exception $e) {
          $whyorderinvalid = ' Order exception is ' . $e->getMessage();

          foreach ($this->certificateKeys as $file) {
            if (is_file($file) && stripos($file, 'certificate.crt') === false && stripos($file, 'private.pem') === false) unlink($file);
          }

          if ($this->log instanceof \Psr\Log\LoggerInterface) {
            $this->log->info('Order data for \'' . $this->basename . '\' invalid. Deleting order data and creating new order.');
          } elseif ($this->log >= LEClient::LOG_STATUS) LEFunctions::log('Order data for \'' . $this->basename . '\' invalid. Deleting order data and creating new order.' . $whyorderinvalid, 'function LEOrder __construct');
          $this->createOrder($domains, $notBefore, $notAfter);
        }
      } else {
        $whyorderinvalid = ' Order url is ' . $this->orderURL;

        foreach ($this->certificateKeys as $file) {
          if (is_file($file)) unlink($file);
        }


        if ($this->log instanceof \Psr\Log\LoggerInterface) {
          $this->log->info('Order data for \'' . $this->basename . '\' invalid. Deleting order data and creating new order.');
        } elseif ($this->log >= LEClient::LOG_STATUS) LEFunctions::log('Order data for \'' . $this->basename . '\' invalid. Deleting order data and creating new order.' . $whyorderinvalid, 'function LEOrder __construct');

        $this->createOrder($domains, $notBefore, $notAfter);
      }
    } else {
      if ($this->log instanceof \Psr\Log\LoggerInterface) {
        $this->log->info('No order found for \'' . $this->basename . '\'. Creating new order.');
      } elseif ($this->log >= LEClient::LOG_STATUS) LEFunctions::log('No order found for \'' . $this->basename . '\'. Creating new order.', 'function LEOrder __construct');
      $this->createOrder($domains, $notBefore, $notAfter);
    }
  }

  /**
   * Creates a new LetsEncrypt order and fills this instance with its data. Subsequently creates a new RSA keypair for the certificate.
   *
   * @param array		$domains 	The array of strings containing the domain names on the certificate.
   * @param string 	$notBefore 	A date string formatted like 0000-00-00T00:00:00Z (yyyy-mm-dd hh:mm:ss) at which the certificate becomes valid.
   * @param string 	$notAfter 	A date string formatted like 0000-00-00T00:00:00Z (yyyy-mm-dd hh:mm:ss) until which the certificate is valid.
   */
  private function createOrder($domains, $notBefore, $notAfter)
  {
    $dns = array();
    foreach ($domains as $domain) {
      if (preg_match_all('~(\*\.)~', $domain) > 1) throw LEOrderException::InvalidArgumentException('Cannot create orders with multiple wildcards in one domain.');
      $dns[] = array('type' => 'dns', 'value' => $domain);
    }
    $payload = array("identifiers" => $dns, 'notBefore' => $notBefore, 'notAfter' => $notAfter);
    $sign = $this->connector->signRequestKid($payload, $this->connector->accountURL, $this->connector->newOrder);
    $post = $this->connector->post($this->connector->newOrder, $sign);

    if ($post['status'] === 201) {
      if (preg_match('~Location: (\S+)~i', $post['header'], $matches)) {
        $this->orderURL = trim($matches[1]);
        file_put_contents($this->certificateKeys['order'], $this->orderURL);
        if ($this->keyType == "rsa") {
          LEFunctions::RSAgenerateKeys(null, $this->certificateKeys['private_key'], $this->certificateKeys['public_key'], $this->keySize);
        } elseif ($this->keyType == "ec") {
          LEFunctions::ECgenerateKeys(null, $this->certificateKeys['private_key'], $this->certificateKeys['public_key'], $this->keySize);
        } else {
          throw LEOrderException::InvalidKeyTypeException($this->keyType);
        }

        $this->status = $post['body']['status'];
        $this->expires = $post['body']['expires'];
        $this->identifiers = $post['body']['identifiers'];
        $this->authorizationURLs = $post['body']['authorizations'];
        $this->finalizeURL = $post['body']['finalize'];
        if (array_key_exists('certificate', $post['body'])) $this->certificateURL = $post['body']['certificate'];
        $this->updateAuthorizations();

        if ($this->log instanceof \Psr\Log\LoggerInterface) {
          $this->log->info('Created order for \'' . $this->basename . '\'.');
        } elseif ($this->log >= LEClient::LOG_STATUS) LEFunctions::log('Created order for \'' . $this->basename . '\'.', 'function createOrder (function LEOrder __construct)');
      } else {
        throw LEOrderException::CreateFailedException('New-order returned invalid response.');
      }
    } else {
      throw LEOrderException::CreateFailedException('Creating new order failed.');
    }
  }

  /**
   * Fetches the latest data concerning this LetsEncrypt Order instance and fills this instance with the new data.
   */
  public function updateOrderData()
  {
    $sign = $this->connector->signRequestKid('', $this->connector->accountURL, $this->orderURL);
    $post = $this->connector->post($this->orderURL, $sign);
    if ($post['status'] === 200) {
      $this->status = $post['body']['status'];
      $this->expires = $post['body']['expires'];
      $this->identifiers = $post['body']['identifiers'];
      $this->authorizationURLs = $post['body']['authorizations'];
      $this->finalizeURL = $post['body']['finalize'];
      if (array_key_exists('certificate', $post['body'])) $this->certificateURL = $post['body']['certificate'];
      $this->updateAuthorizations();
    } else {
      if ($this->log instanceof \Psr\Log\LoggerInterface) {
        $this->log->info('Cannot update data for order \'' . $this->basename . '\'.');
      } elseif ($this->log >= LEClient::LOG_STATUS) LEFunctions::log('Cannot update data for order \'' . $this->basename . '\'.', 'function updateOrderData');
    }
  }

  /**
   * Fetches the latest data concerning all authorizations connected to this LetsEncrypt Order instance and creates and stores a new LetsEncrypt Authorization instance for each one.
   */
  private function updateAuthorizations()
  {
    $this->authorizations = array();
    foreach ($this->authorizationURLs as $authURL) {
      if (filter_var($authURL, FILTER_VALIDATE_URL)) {
        $auth = new LEAuthorization($this->connector, $this->log, $authURL);
        if ($auth != false) $this->authorizations[] = $auth;
      }
    }
  }

  /**
   * Walks all LetsEncrypt Authorization instances and returns whether they are all valid (verified).
   *
   * @return boolean	Returns true if all authorizations are valid (verified), returns false if not.
   */
  public function allAuthorizationsValid()
  {
    if (count($this->authorizations) > 0) {
      foreach ($this->authorizations as $auth) {
        if ($auth->status != 'valid') return false;
      }
      return true;
    }
    return false;
  }

  /**
   * Get all pending LetsEncrypt Authorization instances and return the necessary data for verification. The data in the return object depends on the $type.
   *
   * @param int	$type	The type of verification to get. Supporting http-01 and dns-01. Supporting LEOrder::CHALLENGE_TYPE_HTTP and LEOrder::CHALLENGE_TYPE_DNS. Throws
   *						a Runtime Exception when requesting an unknown $type. Keep in mind a wildcard domain authorization only accepts LEOrder::CHALLENGE_TYPE_DNS.
   *
   * @return object	Returns an array with verification data if successful, false if not pending LetsEncrypt Authorization instances were found. The return array always
   *					contains 'type' and 'identifier'. For LEOrder::CHALLENGE_TYPE_HTTP, the array contains 'filename' and 'content' for necessary the authorization file.
   *					For LEOrder::CHALLENGE_TYPE_DNS, the array contains 'DNSDigest', which is the content for the necessary DNS TXT entry.
   */

  public function getPendingAuthorizations($type)
  {
    $authorizations = array();

    $privateKey = openssl_pkey_get_private(file_get_contents($this->connector->accountKeys['private_key']));
    $details = openssl_pkey_get_details($privateKey);

    $header = array(
      "e" => LEFunctions::Base64UrlSafeEncode($details["rsa"]["e"]),
      "kty" => "RSA",
      "n" => LEFunctions::Base64UrlSafeEncode($details["rsa"]["n"])

    );
    $digest = LEFunctions::Base64UrlSafeEncode(hash('sha256', json_encode($header), true));

    foreach ($this->authorizations as $auth) {
      if ($auth->status == 'pending') {
        $challenge = $auth->getChallenge($type);
        if ($challenge['status'] == 'pending') {
          $keyAuthorization = $challenge['token'] . '.' . $digest;
          switch (strtolower($type)) {
            case LEOrder::CHALLENGE_TYPE_HTTP:
              $authorizations[] = array('type' => LEOrder::CHALLENGE_TYPE_HTTP, 'identifier' => $auth->identifier['value'], 'filename' => $challenge['token'], 'content' => $keyAuthorization);
              break;
            case LEOrder::CHALLENGE_TYPE_DNS:
              $DNSDigest = LEFunctions::Base64UrlSafeEncode(hash('sha256', $keyAuthorization, true));
              $authorizations[] = array('type' => LEOrder::CHALLENGE_TYPE_DNS, 'identifier' => $auth->identifier['value'], 'DNSDigest' => $DNSDigest);
              break;
          }
        }
      }
    }

    return count($authorizations) > 0 ? $authorizations : false;
  }

  /**
   * Sends a verification request for a given $identifier and $type. The function itself checks whether the verification is valid before making the request.
   * Updates the LetsEncrypt Authorization instances after a successful verification.
   *
   * @param string	$identifier	The domain name to verify.
   * @param int 		$type 		The type of verification. Supporting LEOrder::CHALLENGE_TYPE_HTTP and LEOrder::CHALLENGE_TYPE_DNS.
   * @param boolean	$localcheck	Whether to verify the authorization locally before making the authorization request to LE. Optional, default to true.
   *
   * @return boolean	Returns true when the verification request was successful, false if not.
   */
  public function verifyPendingOrderAuthorization($identifier, $type, $localcheck = true)
  {
    $privateKey = openssl_pkey_get_private(file_get_contents($this->connector->accountKeys['private_key']));
    $details = openssl_pkey_get_details($privateKey);

    $header = array(
      "e" => LEFunctions::Base64UrlSafeEncode($details["rsa"]["e"]),
      "kty" => "RSA",
      "n" => LEFunctions::Base64UrlSafeEncode($details["rsa"]["n"])

    );
    $digest = LEFunctions::Base64UrlSafeEncode(hash('sha256', json_encode($header), true));

    foreach ($this->authorizations as $auth) {
      if ($auth->identifier['value'] == $identifier) {
        if ($auth->status == 'pending') {
          $challenge = $auth->getChallenge($type);
          if ($challenge['status'] == 'pending') {
            $keyAuthorization = $challenge['token'] . '.' . $digest;
            switch ($type) {
              case LEOrder::CHALLENGE_TYPE_HTTP:
                if ($localcheck == false or LEFunctions::checkHTTPChallenge($identifier, $challenge['token'], $keyAuthorization)) {
                  $sign = $this->connector->signRequestKid(array('keyAuthorization' => $keyAuthorization), $this->connector->accountURL, $challenge['url']);
                  $post = $this->connector->post($challenge['url'], $sign);
                  if ($post['status'] === 200) {
                    update_option('wple_http_valid', 1); //custom flag by WPLE

                    if ($localcheck) {
                      if ($this->log instanceof \Psr\Log\LoggerInterface) {
                        $this->log->info('HTTP challenge for \'' . $identifier . '\' valid.');
                      } elseif ($this->log >= LEClient::LOG_STATUS) LEFunctions::log('HTTP challenge for \'' . $identifier . '\' valid.', 'function verifyPendingOrderAuthorization');
                    }
                    while ($auth->status == 'pending') {
                      sleep(1);
                      $auth->updateData();
                    }
                    return true;
                  }
                } else {
                  if ($this->log instanceof \Psr\Log\LoggerInterface) {
                    $this->log->info('HTTP challenge for \'' . $identifier . '\' tested locally, found invalid.');
                  } elseif ($this->log >= LEClient::LOG_STATUS) LEFunctions::log('HTTP challenge for \'' . $identifier . '\' tested locally, found invalid.', 'function verifyPendingOrderAuthorization');
                }
                break;
              case LEOrder::CHALLENGE_TYPE_DNS:
                $DNSDigest = LEFunctions::Base64UrlSafeEncode(hash('sha256', $keyAuthorization, true));
                if ($localcheck == false or LEFunctions::checkDNSChallenge($identifier, $DNSDigest)) {
                  sleep(10);
                  $sign = $this->connector->signRequestKid(array('keyAuthorization' => $keyAuthorization), $this->connector->accountURL, $challenge['url']);
                  $post = $this->connector->post($challenge['url'], $sign);
                  if ($post['status'] === 200) {
                    if ($localcheck) {
                      if ($this->log instanceof \Psr\Log\LoggerInterface) {
                        $this->log->info('DNS challenge for \'' . $identifier . '\' valid.');
                      } elseif ($this->log >= LEClient::LOG_STATUS) LEFunctions::log('DNS challenge for \'' . $identifier . '\' valid.', 'function verifyPendingOrderAuthorization');
                    }
                    while ($auth->status == 'pending') {
                      sleep(1);
                      $auth->updateData();
                    }
                    return true;
                  }
                } else {
                  if ($this->log instanceof \Psr\Log\LoggerInterface) {
                    $this->log->info('DNS challenge for \'' . $identifier . '\' tested locally, found invalid.');
                  } elseif ($this->log >= LEClient::LOG_STATUS) LEFunctions::log('DNS challenge for \'' . $identifier . '\' tested locally, found invalid.', 'function verifyPendingOrderAuthorization');
                }
                break;
            }
          }
        }
      }
    }
    return false;
  }

  /**
   * Deactivate an LetsEncrypt Authorization instance.
   *
   * @param string	$identifier The domain name for which the verification should be deactivated.
   *
   * @return boolean	Returns true is the deactivation request was successful, false if not.
   */
  public function deactivateOrderAuthorization($identifier)
  {
    foreach ($this->authorizations as $auth) {
      if ($auth->identifier['value'] == $identifier) {
        $sign = $this->connector->signRequestKid(array('status' => 'deactivated'), $this->connector->accountURL, $auth->authorizationURL);
        $post = $this->connector->post($auth->authorizationURL, $sign);
        if ($post['status'] === 200) {
          if ($this->log instanceof \Psr\Log\LoggerInterface) {
            $this->log->info('Authorization for \'' . $identifier . '\' deactivated.');
          } elseif ($this->log >= LEClient::LOG_STATUS) LEFunctions::log('Authorization for \'' . $identifier . '\' deactivated.', 'function deactivateOrderAuthorization');
          $this->updateAuthorizations();
          return true;
        }
      }
    }
    if ($this->log instanceof \Psr\Log\LoggerInterface) {
      $this->log->info('No authorization found for \'' . $identifier . '\', cannot deactivate.');
    } elseif ($this->log >= LEClient::LOG_STATUS) LEFunctions::log('No authorization found for \'' . $identifier . '\', cannot deactivate.', 'function deactivateOrderAuthorization');
    return false;
  }

  /**
   * Generates a Certificate Signing Request for the identifiers in the current LetsEncrypt Order instance. If possible, the base name will be the certificate
   * common name and all domain names in this LetsEncrypt Order instance will be added to the Subject Alternative Names entry.
   *
   * @return string	Returns the generated CSR as string, unprepared for LetsEncrypt. Preparation for the request happens in finalizeOrder()
   */
  public function generateCSR()
  {
    $domains = array_map(function ($dns) {
      return $dns['value'];
    }, $this->identifiers);
    if (in_array($this->basename, $domains)) {
      $CN = $this->basename;
    } elseif (in_array('*.' . $this->basename, $domains)) {
      $CN = '*.' . $this->basename;
    } else {
      $CN = $domains[0];
    }

    $dn = array(
      "commonName" => $CN
    );

    $san = implode(",", array_map(function ($dns) {
      return "DNS:" . $dns;
    }, $domains));
    $tmpConf = tmpfile();
    $tmpConfMeta = stream_get_meta_data($tmpConf);
    $tmpConfPath = $tmpConfMeta["uri"];

    fwrite(
      $tmpConf,
      'HOME = .
			RANDFILE = $ENV::HOME/.rnd
			[ req ]
			default_bits = ' . $this->keySize . '
			default_keyfile = privkey.pem
			distinguished_name = req_distinguished_name
			req_extensions = v3_req
			[ req_distinguished_name ]
			countryName = Country Name (2 letter code)
			[ v3_req ]
			basicConstraints = CA:FALSE
			subjectAltName = ' . $san . '
			keyUsage = nonRepudiation, digitalSignature, keyEncipherment'
    );

    $privateKey = openssl_pkey_get_private(file_get_contents($this->certificateKeys['private_key']));
    $csr = openssl_csr_new($dn, $privateKey, array('config' => $tmpConfPath, 'digest_alg' => 'sha256'));
    openssl_csr_export($csr, $csr);
    return $csr;
  }

  /**
   * Checks, for redundancy, whether all authorizations are valid, and finalizes the order. Updates this LetsEncrypt Order instance with the new data.
   *
   * @param string	$csr	The Certificate Signing Request as a string. Can be a custom CSR. If empty, a CSR will be generated with the generateCSR() function.
   *
   * @return boolean	Returns true if the finalize request was successful, false if not.
   */
  public function finalizeOrder($csr = '')
  {
    $this->updateOrderData();
    if ($this->status == 'ready') {
      if ($this->allAuthorizationsValid()) {
        if (empty($csr)) $csr = $this->generateCSR();
        file_put_contents($this->keysdir . 'csr.crt', $csr);
        if (preg_match('~-----BEGIN\sCERTIFICATE\sREQUEST-----(.*)-----END\sCERTIFICATE\sREQUEST-----~s', $csr, $matches)) $csr = $matches[1];
        $csr = trim(LEFunctions::Base64UrlSafeEncode(base64_decode($csr)));
        $sign = $this->connector->signRequestKid(array('csr' => $csr), $this->connector->accountURL, $this->finalizeURL);
        $post = $this->connector->post($this->finalizeURL, $sign);
        if ($post['status'] === 200) {
          $this->status = $post['body']['status'];
          $this->expires = $post['body']['expires'];
          $this->identifiers = $post['body']['identifiers'];
          $this->authorizationURLs = $post['body']['authorizations'];
          $this->finalizeURL = $post['body']['finalize'];
          if (array_key_exists('certificate', $post['body'])) $this->certificateURL = $post['body']['certificate'];
          $this->updateAuthorizations();
          if ($this->log instanceof \Psr\Log\LoggerInterface) {
            $this->log->info('Order for \'' . $this->basename . '\' finalized.');
          } elseif ($this->log >= LEClient::LOG_STATUS) LEFunctions::log('Order for \'' . $this->basename . '\' finalized.', 'function finalizeOrder');
          return true;
        }
      } else {
        if ($this->log instanceof \Psr\Log\LoggerInterface) {
          $this->log->info('Not all authorizations are valid for \'' . $this->basename . '\'. Cannot finalize order.');
        } elseif ($this->log >= LEClient::LOG_STATUS) LEFunctions::log('Not all authorizations are valid for \'' . $this->basename . '\'. Cannot finalize order.', 'function finalizeOrder');
      }
    } else {
      if ($this->log instanceof \Psr\Log\LoggerInterface) {
        $this->log->info('Order status for \'' . $this->basename . '\' is \'' . $this->status . '\'. Cannot finalize order.');
      } elseif ($this->log >= LEClient::LOG_STATUS) LEFunctions::log('Order status for \'' . $this->basename . '\' is \'' . $this->status . '\'. Cannot finalize order.', 'function finalizeOrder');
    }
    return false;
  }

  /**
   * Gets whether the LetsEncrypt Order is finalized by checking whether the status is processing or valid. Keep in mind, a certificate is not yet available when the status still is processing.
   *
   * @return boolean	Returns true if finalized, false if not.
   */
  public function isFinalized()
  {
    return ($this->status == 'processing' || $this->status == 'valid');
  }

  /**
   * Requests the certificate for this LetsEncrypt Order instance, after finalization. When the order status is still 'processing', the order will be polled max
   * four times with five seconds in between. If the status becomes 'valid' in the meantime, the certificate will be requested. Else, the function returns false.
   *
   * @return boolean	Returns true if the certificate is stored successfully, false if the certificate could not be retrieved or the status remained 'processing'.
   */
  public function getCertificate()
  {
    $polling = 0;
    while ($this->status == 'processing' && $polling < 4) {
      if ($this->log instanceof \Psr\Log\LoggerInterface) {
        $this->log->info('Certificate for \'' . $this->basename . '\' being processed. Retrying in 5 seconds...');
      } elseif ($this->log >= LEClient::LOG_STATUS) LEFunctions::log('Certificate for \'' . $this->basename . '\' being processed. Retrying in 5 seconds...', 'function getCertificate');
      sleep(5);
      $this->updateOrderData();
      $polling++;
    }
    if ($this->status == 'valid') {
      if (!empty($this->certificateURL)) {
        $sign = $this->connector->signRequestKid('', $this->connector->accountURL, $this->certificateURL);
        $post = $this->connector->post($this->certificateURL, $sign);
        if ($post['status'] === 200) {
          $matches = [];
          if (preg_match_all('~(-----BEGIN\sCERTIFICATE-----[\s\S]+?-----END\sCERTIFICATE-----)~i', $post['body'], $matches)) {
            if (isset($this->certificateKeys['certificate'])) file_put_contents($this->certificateKeys['certificate'],  $matches[0][0]);

            if (count($matches[0]) > 1 && isset($this->certificateKeys['fullchain_certificate'])) {
              $fullchain = $matches[0][0] . "\n";
              $lastbutone = count($matches[0]) - 2;
              if (isset($this->certificateKeys['cabundle'])) file_put_contents($this->certificateKeys['cabundle'],  $matches[0][$lastbutone]);

              for ($i = 1; $i < count($matches[0]); $i++) {
                $fullchain .= $matches[0][$i] . "\n";
              }
              file_put_contents(trim($this->certificateKeys['fullchain_certificate']), $fullchain);
            }
            if ($this->log instanceof \Psr\Log\LoggerInterface) {
              $this->log->info('Certificate for \'' . $this->basename . '\' saved');
            } elseif ($this->log >= LEClient::LOG_STATUS) LEFunctions::log('Certificate for \'' . $this->basename . '\' saved', 'function getCertificate');
            return true;
          } else {
            if ($this->log instanceof \Psr\Log\LoggerInterface) {
              $this->log->info('Received invalid certificate for \'' . $this->basename . '\'. Cannot save certificate.');
            } elseif ($this->log >= LEClient::LOG_STATUS) LEFunctions::log('Received invalid certificate for \'' . $this->basename . '\'. Cannot save certificate.', 'function getCertificate');
          }
        } else {
          if ($this->log instanceof \Psr\Log\LoggerInterface) {
            $this->log->info('Invalid response for certificate request for \'' . $this->basename . '\'. Cannot save certificate.');
          } elseif ($this->log >= LEClient::LOG_STATUS) LEFunctions::log('Invalid response for certificate request for \'' . $this->basename . '\'. Cannot save certificate.', 'function getCertificate');
        }
      } else {
        if ($this->log instanceof \Psr\Log\LoggerInterface) {
          $this->log->info('Order for \'' . $this->basename . '\' not valid. Cannot find certificate URL.');
        } elseif ($this->log >= LEClient::LOG_STATUS) LEFunctions::log('Order for \'' . $this->basename . '\' not valid. Cannot find certificate URL.', 'function getCertificate');
      }
    } else {
      if ($this->log instanceof \Psr\Log\LoggerInterface) {
        $this->log->info('Order for \'' . $this->basename . '\' not valid. Cannot retrieve certificate.');
      } elseif ($this->log >= LEClient::LOG_STATUS) LEFunctions::log('Order for \'' . $this->basename . '\' not valid. Cannot retrieve certificate.', 'function getCertificate');
    }
    return false;
  }

  /**
   * Revokes the certificate in the current LetsEncrypt Order instance, if existent. Unlike stated in the ACME draft, the certificate revoke request cannot be signed
   * with the account private key, and will be signed with the certificate private key.
   *
   * @param int	$reason   The reason to revoke the LetsEncrypt Order instance certificate. Possible reasons can be found in section 5.3.1 of RFC5280.
   *
   * @return boolean	Returns true if the certificate was successfully revoked, false if not.
   */
  public function revokeCertificate($reason = 0)
  {
    if ($this->status == 'valid' || $this->status == 'ready') {
      if (isset($this->certificateKeys['certificate'])) $certFile = $this->certificateKeys['certificate'];
      elseif (isset($this->certificateKeys['fullchain_certificate']))  $certFile = $this->certificateKeys['fullchain_certificate'];
      else throw LEOrderException::InvalidConfigurationException('certificateKeys[certificate] or certificateKeys[fullchain_certificate] required');

      if (file_exists($certFile) && file_exists($this->certificateKeys['private_key'])) {
        $certificate = file_get_contents($this->certificateKeys['certificate']);
        preg_match('~-----BEGIN\sCERTIFICATE-----(.*)-----END\sCERTIFICATE-----~s', $certificate, $matches);
        $certificate = trim(LEFunctions::Base64UrlSafeEncode(base64_decode(trim($matches[1]))));

        $sign = $this->connector->signRequestJWK(array('certificate' => $certificate, 'reason' => $reason), $this->connector->revokeCert, $this->certificateKeys['private_key']);
        $post = $this->connector->post($this->connector->revokeCert, $sign);
        if ($post['status'] === 200) {
          if ($this->log instanceof \Psr\Log\LoggerInterface) {
            $this->log->info('Certificate for order \'' . $this->basename . '\' revoked.');
          } elseif ($this->log >= LEClient::LOG_STATUS) LEFunctions::log('Certificate for order \'' . $this->basename . '\' revoked.', 'function revokeCertificate');
          return true;
        } else {
          if ($this->log instanceof \Psr\Log\LoggerInterface) {
            $this->log->info('Certificate for order \'' . $this->basename . '\' cannot be revoked.');
          } elseif ($this->log >= LEClient::LOG_STATUS) LEFunctions::log('Certificate for order \'' . $this->basename . '\' cannot be revoked.', 'function revokeCertificate');
        }
      } else {
        if ($this->log instanceof \Psr\Log\LoggerInterface) {
          $this->log->info('Certificate for order \'' . $this->basename . '\' not found. Cannot revoke certificate.');
        } elseif ($this->log >= LEClient::LOG_STATUS) LEFunctions::log('Certificate for order \'' . $this->basename . '\' not found. Cannot revoke certificate.', 'function revokeCertificate');
      }
    } else {
      if ($this->log instanceof \Psr\Log\LoggerInterface) {
        $this->log->info('Order for \'' . $this->basename . '\' not valid. Cannot revoke certificate.');
      } elseif ($this->log >= LEClient::LOG_STATUS) LEFunctions::log('Order for \'' . $this->basename . '\' not valid. Cannot revoke certificate.', 'function revokeCertificate');
    }
    return false;
  }
}
