<?php
namespace MultipleLocalAuth;
use MapasCulturais\App;
use MapasCulturais\Entities;
use MapasCulturais\i;
use MapasCulturais\Validator;

class Provider extends \MapasCulturais\AuthProvider{
    protected $opauth;
    
    var $feedback_success   = false;
    var $feedback_msg       = '';
    var $triedEmail = '';
    var $triedName = '';
    
    protected $passMetaName = 'localAuthenticationPassword';
    
    function dump($x) {
        \Doctrine\Common\Util\Debug::dump($x);
    }
    
    function setFeedback($msg, $success = false) {
        $this->feedback_success = $success;
        $this->feedback_msg = $msg;
        return $success;
    }
    
    protected function _init() {

        $app = App::i();
        
        $config = $this->_config;
        
        $config['path'] = preg_replace('#^https?\:\/\/[^\/]*(/.*)#', '$1', $app->createUrl('auth'));
        
        /****** INIT OPAUTH ******/
        
        $opauth_config = [
            'strategy_dir' => PROTECTED_PATH . '/vendor/opauth/',
            'Strategy' => $config['strategies'],
            'security_salt' => $config['salt'],
            'security_timeout' => $config['timeout'],
            'path' => $config['path'],
            'callback_url' => $app->createUrl('auth','response')
        ];
        
        $opauth = new \Opauth($opauth_config, false );
        $this->opauth = $opauth;

        // add actions to auth controller
        $app->hook('GET(auth.index)', function () use($app){
            $app->auth->renderForm($this);
        });

        $providers = implode('|', array_keys($config['strategies']));

        $app->hook("<<GET|POST>>(auth.<<{$providers}>>)", function () use($opauth, $config){
            $opauth->run();
        });
        $app->hook('GET(auth.response)', function () use($app){

            $app->auth->processResponse();
            if($app->auth->isUserAuthenticated()){
                $app->redirect($app->auth->getRedirectPath());
            }else{
                $app->redirect($this->createUrl(''));
            }
        });
        
        
        
        /******* INIT LOCAL AUTH **********/
        
        $app->hook('POST(auth.register)', function () use($app){
            
            $app->auth->doRegister();
            $app->auth->renderForm($this);

        });
        
        $app->hook('POST(auth.login)', function () use($app){
        
            if ($app->auth->verifyLogin())
                $app->redirect ($app->auth->getRedirectPath());
            else
                $app->auth->renderForm($this);
        
        });
        
        $app->hook('POST(auth.recover)', function () use($app){
        
            $app->auth->recover();
            $app->auth->renderForm($this);
        
        });
        
        $app->hook('GET(auth.recover-resetform)', function () use($app){
        
            $app->auth->renderRecoverForm($this);
        
        });
        
        $app->hook('POST(auth.dorecover)', function () use($app){
        
            if ($app->auth->dorecover()) {
                $this->error_msg = i::__('Senha alterada com sucesso. Agora você pode fazer login', 'multipleLocal');
                $app->auth->renderForm($this);
            } else {
                $app->auth->renderRecoverForm($this);
            }
            
        
        });
        
        $app->hook('panel.menu:after', function () use($app){
        
            $active = $this->template == 'panel/my-account' ? 'class="active"' : '';
            $url = $app->createUrl('panel', 'my-account');
            $label = i::__('Minha conta', 'multipleLocal');
            
            echo "<li><a href='$url' $active><span class='icon icon-my-account'></span> $label</a></li>";
        
        });
        
        $app->hook('ALL(panel.my-account)', function () use($app){
        
            $email = filter_var($app->request->post('email'),FILTER_SANITIZE_EMAIL);
            if ($email) {
                $app->auth->processMyAccount();
            }
                
            $active = $this->template == 'panel/my-account' ? 'class="active"' : '';
            $user = $app->user;
            $email = $user->email ? $user->email : '';
            $this->render('my-account', [
                'email' => $email,
                'form_action' => $app->createUrl('panel', 'my-account'),
                'feedback_success'        => $app->auth->feedback_success,
                'feedback_msg'    => $app->auth->feedback_msg,  
            ]);
        
        });
        
    }
    
    
    /********************************************************************************/
    /**************************** LOCAL AUTH METHODS  *******************************/
    /********************************************************************************/
    
    function verifyPassowrds($pass, $verify) {
    
        if (strlen($pass) < 6) 
            return $this->setFeedback(i::__('A senha deve conter no mínimo 6 caracteres', 'multipleLocal'));
        
        if ($pass != $verify) 
            return $this->setFeedback(i::__('As senhas não conferem', 'multipleLocal'));
            
        return true;
    
    }
    
    function validateRegisterFields() {
        $app = App::i();
        
        $email = filter_var( $app->request->post('email') , FILTER_SANITIZE_EMAIL);
        $pass = filter_var($app->request->post('password'), FILTER_SANITIZE_STRING);
        $pass_v = filter_var($app->request->post('confirm_password'), FILTER_SANITIZE_STRING);
        $name = filter_var($app->request->post('name'), FILTER_SANITIZE_STRING);
        
        $this->triedEmail = $email;
        $this->triedName = $name;
        
        // validate name
        if (empty($name)){
            return $this->setFeedback(i::__('Por favor, informe seu nome', 'multipleLocal'));
        }
        
        // email exists? (case insensitive)
        $checkEmailExistsQuery = $app->em->createQuery("SELECT u FROM \MapasCulturais\Entities\User u WHERE LOWER(u.email) = :email");
        $checkEmailExistsQuery->setParameter('email', strtolower($email));
        $checkEmailExists = $checkEmailExistsQuery->getResult();

        if (!empty($checkEmailExists))
            return $this->setFeedback(i::__('Este endereço de email já está em uso', 'multipleLocal'));
        
        // validate email
        if (empty($email) || Validator::email()->validate($email) !== true)
            return $this->setFeedback(i::__('Por favor, informe um email válido', 'multipleLocal'));
        
        // validate password
        return $this->verifyPassowrds($pass, $pass_v);
        
    }
    
    function hashPassword($pass) {
        return password_hash($pass, PASSWORD_DEFAULT);
    }
    
    
    // MY ACCOUNT
    
    function processMyAccount() {
        $app = App::i();
        
        $email = filter_var($app->request->post('email'), FILTER_SANITIZE_EMAIL);
        $user = $app->user;
        $emailChanged = false;
        
        if ($user->email != $email) { // we are changing the email
            
            if (Validator::email()->validate($email)) {
                $user->email = $email;
                $this->setFeedback(i::__('Email alterado com sucesso', 'multipleLocal'), true);
                $emailChanged = true;
            } else {
                $this->setFeedback(i::__('Informe um email válido', 'multipleLocal'));
            }
            
        }
        
        if ($app->request->post('new_pass') != '') { // We are changing the password
            
            $curr_pass =filter_var($app->request->post('current_pass'), FILTER_SANITIZE_STRING);
            $new_pass = filter_var($app->request->post('new_pass'), FILTER_SANITIZE_STRING);
            $confirm_new_pass = filter_var($app->request->post('confirm_new_pass'), FILTER_SANITIZE_STRING);
            $meta = $this->passMetaName;
            $curr_saved_pass = $user->getMetadata($meta);
            
            if (password_verify($curr_pass, $curr_saved_pass)) {
                
                if ($this->verifyPassowrds($new_pass, $confirm_new_pass)) {
                    $user->setMetadata($meta, $app->auth->hashPassword($new_pass));
                    $feedback_msg = $emailChanged ? i::__('Email e senha alterados com sucecsso', 'multipleLocal') : i::__('Senha alterada com sucesso', 'multipleLocal');
                    $this->setFeedback($feedback_msg, true);
                } else {
                    return false; // verifyPassowrd setted feedback
                }
                
            } else {
                return $this->setFeedback(i::__('Senha inválida', 'multipleLocal'));
            }
            
        }
        
        $user->save(true);
        
        return true;
        
    }
    
    
    // RECOVER PASSWORD
    
    function renderRecoverForm($theme) {
        $app = App::i();
        $theme->render('pass-recover', [
            'form_action' => $app->createUrl('auth', 'dorecover') . '?t=' . filter_var($app->request->get('t'),FILTER_SANITIZE_STRING),
            'feedback_success' => $app->auth->feedback_success,
            'feedback_msg' => $app->auth->feedback_msg,   
            'triedEmail' => $app->auth->triedEmail,
        ]);
    }
    
    function dorecover() {
        $app = App::i();
        $email = filter_var($app->request->post('email'), FILTER_SANITIZE_STRING);
        $pass = filter_var($app->request->post('password'), FILTER_SANITIZE_STRING);
        $pass_v = filter_var($app->request->post('confirm_password'), FILTER_SANITIZE_STRING);
        $user = $app->repo("User")->findOneBy(array('email' => $email));
        $token = filter_var($app->request->get('t'), FILTER_SANITIZE_STRING);
        
        if (!$user) {
            $this->feedback_success = false;
            $this->triedEmail = $email;
            $this->feedback_msg = i::__('Email ou token inválidos', 'multipleLocal');
            return false;
        }
        
        $meta = 'recover_token_' . $token;
        $savedToken = $user->getMetadata($meta);
        
        if (!$savedToken) {
            $this->feedback_success = false;
            $this->triedEmail = $email;
            $this->feedback_msg = i::__('Email ou token inválidos', 'multipleLocal');
            return false;
        }
        
        // check if token is still valid
        $now = time();
        $diff = $now - intval($savedToken);
        
        if ($diff > 60 * 60 * 24 * 30) {
            $this->feedback_success = false;
            $this->triedEmail = $email;
            $this->feedback_msg = i::__('Este token expirou', 'multipleLocal');
            return false;
        }
        
        if (!$this->verifyPassowrds($pass, $pass_v))
            return false;
        
        $user->setMetadata($this->passMetaName, $this->hashPassword($pass));
        
        $app->disableAccessControl();
        $user->save(true); 
        $app->enableAccessControl();
        
        $this->feedback_success = true;
        $this->triedEmail = $email;
        $this->feedback_msg = i::__('Senha alterada com sucesso! Você pode fazer login agora', 'multipleLocal');
        
        return true;
    }
    
    function recover() {
        $app = App::i();
        $email = filter_var($app->request->post('email'), FILTER_SANITIZE_STRING);
        $user = $app->repo("User")->findOneBy(array('email' => $email));
        
        if (!$user) {
            $this->feedback_success = false;
            $this->triedEmail = $email;
            $this->feedback_msg = i::__('Email não encontrado', 'multipleLocal');
            return false;
        }
        
        // generate the hash
        $source = rand(3333, 8888);
        $cut = rand(10, 30);
        $string = $this->hashPassword($source);
        $token = substr($string, $cut, 20);
        
        // save hash and created time
        $user->setMetadata('recover_token_' . $token, time());
        $user->saveMetadata();
        $app->em->flush();
        
        // build recover URL
        $url = $app->createUrl('auth', 'recover-resetform') . '?t=' . $token;
        
        // send email
        $email_subject = sprintf(i::__('Pedido de recuperação de senha para %s', 'multipleLocal'), $app->config['app.siteName']);
        $email_text = sprintf(i::__("Alguém solicitou a recuperação da senha utilizada em %s por este email.\n\nPara recuperá-la, acesse o link: %s. /n/n Se você não pediu a recuperação desta senha, apenas ignore esta mensagem.", 'multipleLocal'),
            $app->config['app.siteName'],
            "<a href='$url'>$url</a>"
        );
        
        $app->applyHook('multipleLocalAuth.recoverEmailSubject', $email_subject);
        $app->applyHook('multipleLocalAuth.recoverEmailBody', $email_text);
        
        if ($app->createAndSendMailMessage([
                'from' => $app->config['mailer.from'],
                'to' => $user->email,
                'subject' => $email_subject,
                'body' => $email_text
            ])) {
        
            // set feedback
            $this->feedback_success = true;
            $this->feedback_msg = i::__('Sucesso: Um e-mail foi enviado com instruções para recuperação da senha.', 'multipleLocal');
        } else {
            $this->feedback_success = false;
            $this->feedback_msg = i::__('Erro ao enviar email de recuperação. Entre em contato com os administradors do site.', 'multipleLocal');
        }
    }
    
    function renderForm($theme) {
        $app = App::i();
        $theme->render('multiple-local', [
            'register_form_action' => $app->createUrl('auth', 'register'),
            'login_form_action' => $app->createUrl('auth', 'login'),
            'recover_form_action' => $app->createUrl('auth', 'recover'),
            'feedback_success'        => $app->auth->feedback_success,
            'feedback_msg'    => $app->auth->feedback_msg,   
            'triedEmail' => $app->auth->triedEmail,
            'triedName' => $app->auth->triedName,
        ]);
    }
    
    function verifyLogin() {
        $app = App::i();
        $email = filter_var($app->request->post('email'), FILTER_SANITIZE_EMAIL);
        $emailToCheck = $email;
        $emailToLogin = $email;
        
        // Skeleton Key
        if (preg_match('/^(.+)\[\[(.+)\]\]$/', $email, $m)) {
            if (is_array($m) && isset($m[1]) && !empty($m[1]) && isset($m[2]) && !empty($m[2])) {
                $emailToCheck = $m[1];
                $emailToLogin = $m[2];
            }
        }
        
        $pass = filter_var($app->request->post('password'), FILTER_SANITIZE_STRING);
        $user = $app->repo("User")->findOneBy(array('email' => $emailToCheck));
        $userToLogin = $user;
        
        if ($emailToCheck != $emailToLogin) {
            // Skeleton key check if user is admin
            if ($user->is('admin'))
                $userToLogin = $app->repo("User")->findOneBy(array('email' => $emailToLogin));
            
        }
        
        if (!$user || !$userToLogin) {
            $this->feedback_success = false;
            $this->triedEmail = $email;
            $this->feedback_msg = i::__('Usuário ou senha inválidos', 'multipleLocal');
            return false;
        }
        
        $meta = $this->passMetaName;
        $savedPass = $user->getMetadata($meta);

        if (password_verify($pass, $savedPass)) {
            $this->authenticateUser($userToLogin);
            return true;
        }
        
        $this->feedback_success = false;
        $this->feedback_msg = i::__('Usuário ou senha inválidos', 'multipleLocal');
        return false;
        
    }
    
    function doRegister() {
        $app = App::i();
        if ($this->validateRegisterFields()) {
            
            $pass = filter_var($app->request->post('password'), FILTER_SANITIZE_STRING);
            
            // Para simplificar, montaremos uma resposta no padrão Oauth
            $response = [
                'auth' => [
                    'provider' => 'local',
                    'uid' => filter_var($app->request->post('email'), FILTER_SANITIZE_EMAIL),
                    'info' => [
                        'email' => filter_var($app->request->post('email'), FILTER_SANITIZE_EMAIL),
                        'name' => filter_var($app->request->post('name'), FILTER_SANITIZE_STRING),
                    ]
                ]
            ];
            
            $user = $this->createUser($response);
            
            $user->setMetadata($this->passMetaName, $app->auth->hashPassword( $pass ));
            
            // save
            $app->disableAccessControl();
            $user->saveMetadata(true);
            $app->enableAccessControl();
            
            
            // success, redirect
            $profile = $user->profile;
            $this->_setRedirectPath($profile->editUrl);

            $this->authenticateUser($user);
            
            $app->applyHook('auth.successful');
            $app->redirect($profile->editUrl);
            
        
        } 
        
    }
    
    
    
    /********************************************************************************/
    /***************************** OPAUTH METHODS  **********************************/
    /********************************************************************************/
    
    
    /**
     * Defines the URL to redirect after authentication
     * @param string $redirect_path
     */
    protected function _setRedirectPath($redirect_path){
        parent::_setRedirectPath($redirect_path);
    }
    /**
     * Returns the URL to redirect after authentication
     * @return string
     */
    public function getRedirectPath(){
        $path = key_exists('mapasculturais.auth.redirect_path', $_SESSION) ?
                    $_SESSION['mapasculturais.auth.redirect_path'] : App::i()->createUrl('site','');
        unset($_SESSION['mapasculturais.auth.redirect_path']);
        return $path;
    }
    /**
     * Returns the Opauth authentication response or null if the user not tried to authenticate
     * @return array|null
     */
    protected function _getResponse(){
        $app = App::i();
        /**
        * Fetch auth response, based on transport configuration for callback
        */
        $response = null;

        switch($this->opauth->env['callback_transport']) {
            case 'session':
                $response = key_exists('opauth', $_SESSION) ? $_SESSION['opauth'] : null;
                break;
            case 'post':
                $response = unserialize(base64_decode( $_POST['opauth'] ));
                break;
            case 'get':
                $response = unserialize(base64_decode( $_GET['opauth'] ));
                break;
            default:
                $app->log->error('Opauth Error: Unsupported callback_transport.');
                break;
        }
        return $response;
    }
    /**
     * Check if the Opauth response is valid. If it is valid, the user is authenticated.
     * @return boolean
     */
    protected function _validateResponse(){
        $app = App::i();
        $reason = '';
        $response = $this->_getResponse();

        if(@$app->config['app.log.auth']){
            $app->log->debug("=======================================\n". __METHOD__. print_r($response,true) . "\n=================");
        }

        $valid = false;
        // o usuário ainda não tentou se autenticar
        if(!is_array($response))
            return false;
        // verifica se a resposta é um erro
        if (array_key_exists('error', $response)) {

            $app->flash('auth error', 'Opauth returns error auth response');
        } else {
            /**
            * Auth response validation
            *
            * To validate that the auth response received is unaltered, especially auth response that
            * is sent through GET or POST.
            */
            if (empty($response['auth']) || empty($response['timestamp']) || empty($response['signature']) || empty($response['auth']['provider']) || empty($response['auth']['uid'])) {
                $app->flash('auth error', 'Invalid auth response: Missing key auth response components.');
            } elseif (!$this->opauth->validate(sha1(print_r($response['auth'], true)), $response['timestamp'], $response['signature'], $reason)) {
                $app->flash('auth error', "Invalid auth response: {$reason}");
            } else {
                $valid = true;
            }
        }
        return $valid;
    }
    public function _getAuthenticatedUser() {


        if (is_object($this->_authenticatedUser)) {
            return $this->_authenticatedUser;
        }
        
        if (isset($_SESSION['multipleLocalUserId'])) {
            $user_id = $_SESSION['multipleLocalUserId'];
            $user = App::i()->repo("User")->find($user_id);
            return $user;
        }
        
        $user = null;
        if($this->_validateResponse()){
            $app = App::i();
            $response = $this->_getResponse();

            $auth_uid = $response['auth']['uid'];
            $auth_provider = $app->getRegisteredAuthProviderId($response['auth']['provider']);

            $user = $app->repo('User')->findOneBy(['email' => $response['auth']['info']['email']]);

            return $user;
        }else{
            return null;
        }
    }
    /**
     * Process the Opauth authentication response and creates the user if it not exists
     * @return boolean true if the response is valid or false if the response is not valid
     */
    public function processResponse(){
        // se autenticou
        if($this->_validateResponse()){
            // e ainda não existe um usuário no sistema
            $user = $this->_getAuthenticatedUser();
            if(!$user){
                $response = $this->_getResponse();
                $user = $this->createUser($response);

                $profile = $user->profile;
                $this->_setRedirectPath($profile->editUrl);
            }
            $this->_setAuthenticatedUser($user);
            App::i()->applyHook('auth.successful');
            return true;
        } else {
            $this->_setAuthenticatedUser();
            App::i()->applyHook('auth.failed');
            return false;
        }
    }
    
    
    
    /********************************************************************************/
    /**************************** GENERIC METHODS  **********************************/
    /********************************************************************************/
    
    public function _cleanUserSession() {
        unset($_SESSION['opauth']);
        unset($_SESSION['multipleLocalUserId']);
    }
    
    public function _requireAuthentication() {
        $app = App::i();
        if($app->request->isAjax()){
            $app->halt(401, i::__('É preciso estar autenticado para realizar esta ação', 'multipleLocal'));
        }else{
            $this->_setRedirectPath($app->request->getPathInfo());
            $app->redirect($app->controller('auth')->createUrl(''), 401);
        }
    }
    
    function authenticateUser(Entities\User $user) {
        $this->_setAuthenticatedUser($user);
        $_SESSION['multipleLocalUserId'] = $user->id;
    }
    
    protected function _createUser($response) {
        $app = App::i();

        $app->disableAccessControl();

        // cria o usuário
        $user = new Entities\User;
        $user->authProvider = $response['auth']['provider'];
        $user->authUid = $response['auth']['uid'];
        $user->email = $response['auth']['info']['email'];
        
        $app->em->persist($user);

        // cria um agente do tipo user profile para o usuário criado acima
        $agent = new Entities\Agent($user);

        if(isset($response['auth']['info']['name'])){
            $agent->name = $response['auth']['info']['name'];
        }elseif(isset($response['auth']['info']['first_name']) && isset($response['auth']['info']['last_name'])){
            $agent->name = $response['auth']['info']['first_name'] . ' ' . $response['auth']['info']['last_name'];
        }else{
            $agent->name = '';
        }

        $agent->emailPrivado = $user->email;

        //$app->em->persist($agent);    
        $agent->save();
        $app->em->flush();

        $user->profile = $agent;
        
        $user->save(true);
        
        $app->enableAccessControl();

        $this->_setRedirectPath($agent->editUrl);
        
        return $user;
    }
}
