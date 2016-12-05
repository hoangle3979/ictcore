<?php
/* * ***************************************************************
 * Copyright © 2014 ICT Innovations Pakistan All Rights Reserved   *
 * Developed By: Nasir Iqbal                                       *
 * Website : http://www.ictinnovations.com/                        *
 * Mail : nasir@ictinnovations.com                                 *
 * *************************************************************** */

class Kannel extends Gateway
{

  /** @const */
  const GATEWAY_FLAG = 2;
  const GATEWAY_TYPE = 'kannel';
  const CONTACT_FIELD = 'phone';

  /** @var boolean $conn */
  protected $conn = false;

  /** @var string $username */
  protected $username;

  /** @var string $password */
  protected $password;

  /** @var string $port */
  protected $port;

  /** @var string $host */
  protected $host;

  /** @var string $path */
  protected $path;

  public function __construct()
  {
    $this->username = conf_get('kannel:user', 'myuser');
    $this->password = conf_get('kannel:pass', 'mypass');
    $this->host = conf_get('kannel:host', '127.0.0.1');
    $this->port = conf_get('kannel:port', '13013');
    $this->path = conf_get('kannel:path', '/cgi-bin/sendsms');
  }

  protected function connect()
  {

    $this->conn = fsockopen($this->host, $this->port);
    socket_set_blocking($this->conn, false);
    if ($this->conn) {
      Corelog::log("Kannel connected successfully", Corelog::CRUD);
      return $this->conn;
    } else {
      Corelog::log("Kannel connection failed", Corelog::ERROR);
      return false;
    }
  }

  protected function dissconnect()
  {
    Corelog::log("Kannel disconnect requested", Corelog::CRUD);
    return fclose($this->conn);
  }

  public function send($command, Provider $oProvider = NULL)
  {
    if (empty($oProvider)) {
      Corelog::log("Kannel sending commands", Corelog::CRUD, $command);
    } else {
      Corelog::log("Kannel sending commands via:".$oProvider->name, Corelog::CRUD, $command);
    }

    $queryString = array(
        'username' => $this->username,
        'password' => $this->password
    );

    // check if we can update application status via delivery report, if so use it
    if (!empty($command['application_id']) && !empty($command['spool_id'])) {
      /* delivery report settings
        DLR-Mask
        1: Delivered to phone,
        2: Non-Delivered to Phone,
        4: Queued on SMSC,
        8: Delivered to SMSC,
        16: Non-Delivered to SMSC.
       */
      $spool_id = $command['spool_id'];
      $dlrMask = 1 | 2 | 4 | 8 | 16;
      $rsp_url = conf_get('site:base_url', 'http://localhost/ictcore') . '/gateway.php';
      $dlrUrl = "$rsp_url?spool_id=$spool_id&gateway_flag=" . Kannel::GATEWAY_FLAG;
      $dlrUrl .= "&application_data[result]=%d&application_data[error]=%A";

      $queryString['dlr-mask'] = $dlrMask;
      $queryString['dlr-url'] = $dlrUrl;
    }

    foreach ($command as $variable => $value) {
      switch ($variable) {
        case 'dlr-url':
          $queryString[] = "dlr-url=" . rawurlencode($value);
          break;
        case 'data':
          $queryString[] = "text=" . rawurlencode($value);
          break;
        default:
          $queryString[] = "$variable=" . urlencode($value);
          break;
      }
    }
    $URL = $this->path . '?' . implode('&', $queryString);

    $this->connect();

    if ($this->conn) {
      fputs($this->conn, "GET $URL HTTP/1.0\r\n\r\n");
      usleep(100); //allow time for response
      $response = "";
      try {
        while (!feof($this->conn)) {
          $response .= fgets($this->conn, 128);
        }
      } catch (Exception $ex) {
        Corelog::log($ex->getMessage(), Corelog::WARNING);
        Corelog::log('Unable to read kannel response', Corelog::WARNING);
      }

      return $response;
    } else {
      return false;
    }

    $this->dissconnect();


    /*     * ******************************************************************
     * update that application has been completed
     * ********************************************************************* */
    $oRequest = new Request();
    $oRequest->gateway_flag = Kannel::GATEWAY_FLAG;
    $oRequest->spool_id = $command['spool_id'];

    $oRequest->application_id = $command['application_id'];
    $oRequest->application_data = array(
        'amount' => 1,
        'amount_net' => 1,
        'status' => 'completed',
        'response' => '',
        'result' => 'success',
        'error' => ''
    );

    /* NOTE: 
     * under apache multithreading does not work
     * so we have to use scheduling
     */
    $oRequest->task_create();
    /*     * **************************************************** UPDATE END */
  }

  private function config_filename($type, $name)
  {
    global $path_etc;
    switch ($type) {
      case 'did':
        return $path_etc . DIRECTORY_SEPARATOR . "kannel/provider/$name.xml";
      case 'smpp':
        return $path_etc . DIRECTORY_SEPARATOR . "kannel/provider/$name.xml";
    }
    return false;
  }

  public function config_save($type, $name, $data = '')
  {
    Corelog::log("Kannel saving config for type: $type, name: $name", Corelog::CRUD);
    $config_file = $this->config_filename($type, $name);
    file_put_contents($config_file, $data);
  }

  public function config_delete($type, $name)
  {
    Corelog::log("Kannel deleting config for type: $type, name: $name", Corelog::CRUD);
    $config_file = $this->config_filename($type, $name);
    unlink($config_file);
  }

  public function config_reload()
  {
    // TODO: develop reload method for kannel
    parent::config_reload();
  }

}