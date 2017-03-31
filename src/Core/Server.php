<?php
/**
 * Created by PhpStorm.
 * User: Hanson
 * Date: 2016/12/9
 * Time: 21:10
 */

namespace Hanson\Vbot\Core;


use Endroid\QrCode\QrCode;
use Hanson\Vbot\Support\Console;
use Hanson\Vbot\Support\FileManager;
use Hanson\Vbot\Support\Path;
use Hanson\Vbot\Support\System;

class Server
{

    static $instance;

    protected $uuid;

    protected $redirectUri;

    public $skey;

    public $sid;

    public $uin;

    public $passTicket;

    public $deviceId;

    public $baseRequest;

    public $syncKey;

    public $syncKeyStr;

    public $config;

    public $messageHandler;

    protected $debug = false;

    public $baseUri = 'https://wx2.qq.com/cgi-bin/mmwebwx-bin';

    public $fileUri;

    public $pushUri;

    public function __construct($config = [])
    {
        $this->config = $config;

        $this->config['debug'] = isset($this->config['debug']) ? $this->config['debug'] : false;
    }

    /**
     * @param array $config
     * @return Server
     */
    public static function getInstance($config = [])
    {
        if(!static::$instance){
            static::$instance = new Server($config);
        }

        return static::$instance;
    }

    /**
     * start a wechat trip
     */
    public function run()
    {
        if(!$this->tryLogin()){
            $this->prepare();
        }

        $this->init();
        Console::log('初始化成功');

        $this->statusNotify();
        Console::log('当前session：' . $this->config['session']);
        Console::log('开始初始化联系人');
        $this->initContact();
        Console::log('初始化联系人成功');
        Console::log(sprintf("群数量： %d", group()->count()));
        Console::log(sprintf("联系人数量： %d", contact()->count()));
        Console::log(sprintf("公众号数量： %d", official()->count()));

        MessageHandler::getInstance()->listen();
    }

    /**
     * 尝试登录
     *
     * @return bool
     */
    private function tryLogin() :bool
    {
        System::isWin() ? system('cls') : system('clear');

        if(is_file(Path::getCurrentSessionPath() . 'cookies') && is_file(Path::getCurrentSessionPath() . 'server.json')){

            $configs = json_decode(file_get_contents(Path::getCurrentSessionPath() . 'server.json'), true);

            foreach ($configs as $key => $config) {
                $this->{$key} = $config;
            }

            list($retCode, $selector) = (new Sync())->checkSync();
            $result = (new MessageHandler())->handleCheckSync($retCode, $selector, true);

            if($result && (new Sync())->sync()){
                Console::log('免扫码登录成功');
                return true;
            }
        }

        return false;
    }

    /**
     * 微信登录流程
     */
    public function prepare()
    {
        $this->getUuid();
        $this->generateQrCode();
        Console::showQrCode('https://login.weixin.qq.com/l/' . $this->uuid);
        Console::log('请扫描二维码登录');

        $this->waitForLogin();
        $this->login();
        Console::log('登录成功');
    }

    /**
     * get uuid
     *
     * @throws \Exception
     */
    protected function getUuid()
    {
        $content = http()->get('https://login.weixin.qq.com/jslogin', [
            'appid' => 'wx782c26e4c19acffb',
            'fun' => 'new',
            'lang' => 'zh_CN',
//            '_' => time() * 1000 . random_int(1, 999)
            '_' => time()
        ]);

        preg_match('/window.QRLogin.code = (\d+); window.QRLogin.uuid = \"(\S+?)\"/', $content, $matches);

        if(!$matches){
            Console::log('获取UUID失败', Console::ERROR);
            exit;
        }

        $this->uuid = $matches[2];
    }

    /**
     * generate a login qrcode
     */
    public function generateQrCode()
    {
        $url = 'https://login.weixin.qq.com/l/' . $this->uuid;

        $qrCode = new QrCode($url);

        $file = Path::getCurrentSessionPath() . 'qr.png';

        FileManager::saveTo($file, file_get_contents($url));

        $qrCode->save($file);
    }

    /**
     * waiting user to login
     *
     * @throws \Exception
     */
    protected function waitForLogin()
    {
        $retryTime = 10;
        $tip = 1;

        while($retryTime > 0){
            $url = sprintf('https://login.weixin.qq.com/cgi-bin/mmwebwx-bin/login?tip=%s&uuid=%s&_=%s', $tip, $this->uuid, time());

            $content = http()->get($url);

            preg_match('/window.code=(\d+);/', $content, $matches);

            $code = $matches[1];
            switch($code){
                case '201':
                    Console::log('请点击确认登录微信');
                    $tip = 0;
                    break;
                case '200':
                    preg_match('/window.redirect_uri="(https:\/\/(\S+?)\/\S+?)";/', $content, $matches);

                    $this->redirectUri = $matches[1] . '&fun=new';
                    $url = 'https://%s/cgi-bin/mmwebwx-bin';
                    $this->fileUri = sprintf($url, 'file.'.$matches[2]);
                    $this->pushUri = sprintf($url, 'webpush.'.$matches[2]);
                    $this->baseUri = sprintf($url, $matches[2]);
                    return;
                case '408':
                    Console::log('登录超时，请重试', Console::WARNING);
                    $tip = 1;
                    $retryTime -= 1;
                    sleep(1);
                    break;
                default:
                    Console::log("登录失败，错误码：$code 。请重试", Console::ERROR);
                    $tip = 1;
                    $retryTime -= 1;
                    sleep(1);
                    break;
            }
        }

        Console::log('登录超时，退出应用', Console::ERROR);
        exit;
    }

    /**
     * login wechat
     * @throws \Exception
     */
    public function login()
    {
        $content = http()->get($this->redirectUri);

        $data = (array)simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOCDATA);

        $this->skey = $data['skey'];
        $this->sid = $data['wxsid'];
        $this->uin = $data['wxuin'];
        $this->passTicket = $data['pass_ticket'];

        if(in_array('', [$this->skey, $this->sid, $this->uin, $this->passTicket])){
            Console::log('登录失败', Console::ERROR);
            exit;
        }

        $this->deviceId = 'e' .substr(mt_rand().mt_rand(), 1, 15);

        $this->baseRequest = [
            'Uin' => intval($this->uin),
            'Sid' => $this->sid,
            'Skey' => $this->skey,
            'DeviceID' => $this->deviceId
        ];

        $this->saveServer();
    }

    /**
     * 保存server至本地
     */
    private function saveServer()
    {
        $config = json_encode([
            'skey' => $this->skey,
            'sid' => $this->sid,
            'uin' => $this->uin,
            'passTicket' => $this->passTicket,
            'baseRequest' => $this->baseRequest,
            'baseUri' => $this->baseUri,
            'fileUri' => $this->fileUri,
            'pushUri' => $this->pushUri,
            'config' => $this->config
        ]);

        FileManager::saveTo(Path::getCurrentSessionPath() . 'server.json', $config);
    }

    protected function init($first = true)
    {
        $url = sprintf($this->baseUri . '/webwxinit?r=%d', time());

        $content = http()->json($url, [
            'BaseRequest' => $this->baseRequest
        ]);

        $result = json_decode($content, true);
        $this->generateSyncKey($result, $first);

        myself()->init($result['User']);

        $this->initContactList($result['ContactList']);

        if($result['BaseResponse']['Ret'] != 0){
            System::deleteDir(Path::getCurrentSessionPath());
            Console::log('初始化失败，链接：' . $url, Console::ERROR);
            exit;
        }
    }

    protected function initContactList($contactList)
    {
        if($contactList){
            (new ContactFactory())->setCollections($contactList);
        }
    }

    protected function initContact()
    {
        new ContactFactory();
    }

    /**
     * open wechat status notify
     */
    protected function statusNotify()
    {
        $url = sprintf($this->baseUri . '/webwxstatusnotify?lang=zh_CN&pass_ticket=%s', $this->passTicket);

        http()->json($url, [
            'BaseRequest' => $this->baseRequest,
            'Code' => 3,
            'FromUserName' => myself()->username,
            'ToUserName' => myself()->username,
            'ClientMsgId' => time()
        ]);
    }

    protected function generateSyncKey($result, $first)
    {
        $this->syncKey = $result['SyncKey'];

        $syncKey = [];

        if(is_array($this->syncKey['List'])){
            foreach ($this->syncKey['List'] as $item) {
                $syncKey[] = $item['Key'] . '_' . $item['Val'];
            }
        }elseif($first){
            $this->init(false);
        }

        $this->syncKeyStr = implode('|', $syncKey);
    }

    public function setMessageHandler(\Closure $closure)
    {
        MessageHandler::getInstance()->setMessageHandler($closure);
    }

    public function setCustomerHandler(\Closure $closure)
    {
        MessageHandler::getInstance()->setCustomHandler($closure);
    }

    public function setExitHandler(\Closure $closure)
    {
        MessageHandler::getInstance()->setExitHandler($closure);
    }

    public function setExceptionHandler(\Closure $closure)
    {
        MessageHandler::getInstance()->setExceptionHandler($closure);
    }

    public function setOnceHandler(\Closure $closure)
    {
        MessageHandler::getInstance()->setOnceHandler($closure);
    }
}