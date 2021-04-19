<?php
namespace MultipleLocalAuth;

use MapasCulturais\App;
use MapasCulturais\i;

include('Facebook/FacebookStrategy.php');
include('Google/GoogleStrategy.php');
include('LinkedIn/LinkedInStrategy.php');

class Plugin extends \MapasCulturais\Plugin {
    
    public function _init() {
        $app = App::i();
        
        // register translation text domain
        i::load_textdomain( 'multipleLocal', __DIR__ . "/translations" );
        
        // Load JS & CSS
        $app->hook('<<GET|POST>>(auth.<<*>>)', function() use ($app) {
            $app->view->enqueueScript('app', 'multipleLocal', 'js/multipleLocal.js');
            $app->view->enqueueStyle('app', 'multipleLocal', 'css/multipleLocal.css');
        });
        
        $app->hook('<<GET|POST|ALL>>(panel.<<*>>):before', function() use ($app) {
            $app->view->enqueueStyle('app', 'multipleLocal', 'css/multipleLocal.css');
        });
    }

    public function register() {
        $this->registerUserMetadata(Provider::$passMetaName, ['label' => i::__('Senha')]);
        
        $this->registerUserMetadata(Provider::$recoverTokenMetadata, ['label' => i::__('Token para recuperação de senha')]);
        $this->registerUserMetadata(Provider::$recoverTokenTimeMetadata, ['label' => i::__('Timestamp do token para recuperação de senha')]);
        
    }
    
}
