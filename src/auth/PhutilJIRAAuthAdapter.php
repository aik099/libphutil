<?php

/**
 * Authentication adapter for JIRA OAuth1.
 */
final class PhutilJIRAAuthAdapter extends PhutilOAuth1AuthAdapter {

  // TODO: JIRA tokens expire (after 5 years) and we could surface and store
  // that.

  private $jiraBaseURI;
  private $adapterDomain;
  private $currentSession;
  private $userInfo;

  public function setJIRABaseURI($jira_base_uri) {
    $this->jiraBaseURI = $jira_base_uri;
    return $this;
  }

  public function getJIRABaseURI() {
    return $this->jiraBaseURI;
  }

  public function getAccountID() {
    // Make sure the handshake is finished; this method is used for its
    // side effect by Auth providers.
    $this->getHandshakeData();

    $key = idx($this->getUserInfo(), 'key');

    // Fallback for Jira Cloud.
    if (!$key) {
      return idx($this->getUserInfo(), 'accountId');
    }

    return $key;
  }

  public function getAccountName() {
    $name = idx($this->getUserInfo(), 'name');

    // Fallback for Jira Cloud.
    if (!$name) {
      return idx($this->getUserInfo(), 'accountId');
    }

    return $name;
  }

  public function getAccountImageURI() {
    $avatars = idx($this->getUserInfo(), 'avatarUrls');
    if ($avatars) {
      return idx($avatars, '48x48');
    }
    return null;
  }

  public function getAccountRealName() {
    return idx($this->getUserInfo(), 'displayName');
  }

  public function getAccountEmail() {
    return idx($this->getUserInfo(), 'emailAddress');
  }

  public function getAdapterType() {
    return 'jira';
  }

  public function getAdapterDomain() {
    return $this->adapterDomain;
  }

  public function setAdapterDomain($domain) {
    $this->adapterDomain = $domain;
    return $this;
  }

  protected function getSignatureMethod() {
    return 'RSA-SHA1';
  }

  protected function getRequestTokenURI() {
    return $this->getJIRAURI('plugins/servlet/oauth/request-token');
  }

  protected function getAuthorizeTokenURI() {
    return $this->getJIRAURI('plugins/servlet/oauth/authorize');
  }

  protected function getValidateTokenURI() {
    return $this->getJIRAURI('plugins/servlet/oauth/access-token');
  }

  private function getJIRAURI($path) {
    return rtrim($this->jiraBaseURI, '/').'/'.ltrim($path, '/');
  }

  private function getUserInfo() {
    if ($this->userInfo === null) {
      $this->currentSession = $this->newJIRAFuture('rest/auth/1/session', 'GET')
        ->resolveJSON();

      // The session call gives us the username, but not the user key or other
      // information. Make a second call to get additional information.

      // https://developer.atlassian.com/cloud/jira/platform/deprecation-notice-user-privacy-api-migration-guide

      $params = array(
        'accountId' => $this->currentSession['name'], // For Jira Cloud (personal data anonymized since 1 Oct 2018).
        'username' => $this->currentSession['name'], // For Jira Self-Hosted.
      );

      $this->userInfo = $this->newJIRAFuture('rest/api/2/user', 'GET', $params)
        ->resolveJSON();
    }

    return $this->userInfo;
  }

  public static function newJIRAKeypair() {
    $config = array(
      'digest_alg' => 'sha512',
      'private_key_bits' => 4096,
      'private_key_type' => OPENSSL_KEYTYPE_RSA,
    );

    $res = openssl_pkey_new($config);
    if (!$res) {
      throw new Exception(pht('%s failed!', 'openssl_pkey_new()'));
    }

    $private_key = null;
    $ok = openssl_pkey_export($res, $private_key);
    if (!$ok) {
      throw new Exception(pht('%s failed!', 'openssl_pkey_export()'));
    }

    $public_key = openssl_pkey_get_details($res);
    if (!$ok || empty($public_key['key'])) {
      throw new Exception(pht('%s failed!', 'openssl_pkey_get_details()'));
    }
    $public_key = $public_key['key'];

    return array($public_key, $private_key);
  }


  /**
   * JIRA indicates that the user has clicked the "Deny" button by passing a
   * well known `oauth_verifier` value ("denied"), which we check for here.
   */
  protected function willFinishOAuthHandshake() {
    $jira_magic_word = 'denied';
    if ($this->getVerifier() == $jira_magic_word) {
      throw new PhutilAuthUserAbortedException();
    }
  }

  public function newJIRAFuture($path, $method, $params = array()) {
    if ($method == 'GET') {
      $uri_params = $params;
      $body_params = array();
    } else {
      // For other types of requests, JIRA expects the request body to be
      // JSON encoded.
      $uri_params = array();
      $body_params = phutil_json_encode($params);
    }

    $uri = new PhutilURI($this->getJIRAURI($path), $uri_params);

    // JIRA returns a 415 error if we don't provide a Content-Type header.

    return $this->newOAuth1Future($uri, $body_params)
      ->setMethod($method)
      ->addHeader('Content-Type', 'application/json');
  }

}
