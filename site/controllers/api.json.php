<?php
/**
 * @version     3.0.0
 * @package     com_imc
 * @copyright   Copyright (C) 2014. All rights reserved.
 * @license     GNU AFFERO GENERAL PUBLIC LICENSE Version 3; see LICENSE
 * @author      Ioannis Tsampoulatidis <tsampoulatidis@gmail.com> - https://github.com/itsam
 */

// No direct access.
defined('_JEXEC') or die;

require_once JPATH_COMPONENT.'/controller.php';
require_once JPATH_COMPONENT_SITE . '/helpers/imc.php';
require_once JPATH_COMPONENT_SITE . '/helpers/MCrypt.php';
require_once JPATH_COMPONENT_SITE . '/models/tokens.php';

/**
 * IMC API controller class.
 * Make sure you have mcrypt module enabled
 * e.g. $ sudo php5enmod mcrypt
 *
 * Every request should contain token, m_id, l
 * where *token* is the m-crypted "json_encode(array)" of username, password, timestamp, randomString in the following form:
 * {'u':'username','p':'plain_password','t':'1439592509','r':'i452dgj522'}
 * all casted to strings including the UNIX timestamp time()
 * where *m_id* is the modality ID according to the REST/API key definition in the administrator side
 * where *l* is the 2-letter language code used for for the responses translation (en, el, de, es, etc)
 *
 * Every token is allowed to be used ^only once^ to avoid MITM attacks
 *
 * Check helpers/MCrypt.php for details on how to use Rijndael-128 AES encryption algorithm
 *
 * Please note that for better security it is highly recommended to protect your site with SSL (https)
 */

class ImcControllerApi extends ImcController
{
    private $mcrypt;
    private $keyModel;

    //private $userModel;

    function __construct()
    {
    	$this->mcrypt = new MCrypt();

        JModelLegacy::addIncludePath(JPATH_COMPONENT_ADMINISTRATOR . '/models');
        $this->keyModel = JModelLegacy::getInstance( 'Key', 'ImcModel', array('ignore_request' => true) );

    	//JModelLegacy::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_users/models/');
        //$this->userModel = JModelLegacy::getInstance( 'User', 'UsersModel');

    	parent::__construct();
    }

    private function validateRequest()
    {
        $app = JFactory::getApplication();
        $token = $app->input->getString('token');
        $m_id  = $app->input->getInt('m_id');
        $l     = $app->input->getString('l');

        //1. check necessary arguments are exist
        if(is_null($token) || is_null($m_id) || is_null($l) ){
            $app->enqueueMessage('Either token, m_id (modality), or l (language) are missing', 'error');
            throw new Exception('Request is invalid');
        }

        //check for nonce (existing token)
        if(ImcModelTokens::exists($token)){
            throw new Exception('Token is already used');
        }

        //2. get the appropriate key according to given modality
        $result = $this->keyModel->getItem($m_id);
        $key = $result->skey;
        if(strlen($key) < 16){
            $app->enqueueMessage('Secret key is not 16 characters', 'error');
            throw new Exception('Secret key is invalid. Contact administrator');
        }
        else {
            $this->mcrypt->setKey($key);
        }

        //3. decrypt and check token validity
        $decryptedToken = $this->mcrypt->decrypt($token);
        $objToken = json_decode($decryptedToken);

        if(!is_object($objToken)){
            throw new Exception('Token is invalid');
        }

        if(!isset($objToken->u) || !isset($objToken->p) || !isset($objToken->t) || !isset($objToken->r)) {
            throw new Exception('Token is not well formatted');
        }

        //TODO: Set timeout at options (default is 10 minutes)
        if((time() - $objToken->t) > 10 * 60){
            throw new Exception('Token has expired');
        }

        //4. authenticate user
        $userid = JUserHelper::getUserId($objToken->u);
        $user = JFactory::getUser($userid);

        $match = JUserHelper::verifyPassword($objToken->p, $user->password, $userid);
        if(!$match){
            $app->enqueueMessage('Either username or password do not match', 'error');
            throw new Exception('Token does not match');
        }

        if($user->block){
            $app->enqueueMessage('User is found but probably is not yet activated', 'error');
            throw new Exception('Token user is blocked');
        }

        //5. populate token table
        $record = new stdClass();
        $record->key_id = $m_id;
        $record->user_id = $userid;
        //$record->json_size = $json_size;
        $record->method = $app->input->getMethod();
        $record->token = $token;
        $record->unixtime = $objToken->t;
        ImcModelTokens::insertToken($record); //throws exception on error

        return $userid;
    }

	public function issues()
	{
		$result = null;
		$app = JFactory::getApplication();
		try {
		    $userid = self::validateRequest();
		    //$userid = 569;
			//get necessary arguments
			$minLat = $app->input->getFloat('minLat', null);
			$maxLat = $app->input->getFloat('maxLat', null);
			$minLng = $app->input->getFloat('minLng', null);
			$maxLng = $app->input->getFloat('maxLng', null);
			$limit = $app->input->getInt('limit', 0);

            //get issues model
            $issuesModel = JModelLegacy::getInstance( 'Issues', 'ImcModel', array('ignore_request' => true) );

			if(is_null($minLat) || is_null($maxLat) || is_null($minLng) || is_null($maxLng))
			{
				//TODO: set state so as to get only allowable issues according to userid
				$data = $issuesModel->getItems();
				$result = ImcFrontendHelper::sanitizeIssues($data, $userid);
			}
			else
			{
				$data = $issuesModel->getItemsInBoundaries($minLat, $maxLat, $minLng, $maxLng);
				$result = ImcFrontendHelper::sanitizeIssues($data, $userid);
			}

			//apply restrictions
			foreach($result as $issue)
			{
                if(!$issue->myIssue && $issue->moderation){
                    unset($issue);
                }
                if($issue->state != 1){
                    unset($issue);
                }
			}
			$result = array_values($result);

			echo new JResponseJson($result, 'Issues fetched successfully');
		}
		catch(Exception $e)	{
			echo new JResponseJson($e);
		}
	}	

	public function issue()
	{
		$result = null;
		$app = JFactory::getApplication();
		try {
		    $userid = self::validateRequest();
            //get necessary arguments
            $id = $app->input->getInt('id', null);

            //get issue model
            $issueModel = JModelLegacy::getInstance( 'Issue', 'ImcModel', array('ignore_request' => true) );

            switch($app->input->getMethod())
            {
                //fetch existing issue
                case 'GET':
                    if ($id == null){
                        throw new Exception('Id is not set');
                    }
                    $data = $issueModel->getData($id);
                    if(!is_object($data)){
                        throw new Exception('Issue do not exists');
                    }

                    $result = ImcFrontendHelper::sanitizeIssue($data, $userid);

                    //check for any restrictions
                    if(!$result->myIssue && $result->moderation){
                        throw new Exception('Issue is under moderation');
                    }
                    if($result->state != 1){
                        throw new Exception('Issue is not published');
                    }

                break;
                //create new issue
                case 'POST':
                    if ($id != null){
                        throw new Exception('You cannot use POST to fetch issue. Use GET instead');
                    }
                break;
                //update existing issue
                case 'PUT':
                case 'PATCH':
                    if ($id == null){
                        throw new Exception('Id is not set');
                    }
                break;
                default:
                    throw new Exception('HTTP method is not supported');
            }

            echo new JResponseJson($result, 'Issue fetched successfully');
		}
		catch(Exception $e)	{
			echo new JResponseJson($e);
		}
	}
}