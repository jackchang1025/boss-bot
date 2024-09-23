<?php

namespace App;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\Exception\TimeoutException;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\WebDriverKeys;
use Psr\Log\LoggerInterface;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\ProcessManager\SeleniumManager;

class Bot
{
    protected Client $client;

    /**
     * BossBot构造函数
     *
     * @param LoggerInterface $logger 日志接口
     * @param string $host Selenium服务器地址
     * @param SeleniumManager|null $manager Selenium管理器
     */
    public function __construct(
        protected LoggerInterface $logger,
        protected string $host = 'http://selenium:4444/wd/hub',
        ?SeleniumManager $manager = null
    ) {
        // 初始化Panther客户端
        $this->client = new Client($manager ?? $this->createDefaultManager());
    }

    /**
     * 创建默认的Selenium管理器
     *
     * @return SeleniumManager
     */
    public function createDefaultManager(): SeleniumManager
    {
        // 创建ChromeOptions实例
        $config = new ChromeOptions();

        // 添加Chrome启动参数
        $config->addArguments([
            '--no-sandbox',
            '--disable-gpu',
            '--disable-dev-shm-usage',
            '--start-maximized',
        ]);

        // 设置Chrome的Desired Capabilities
        $capabilities = DesiredCapabilities::chrome();
        $capabilities->setCapability(ChromeOptions::CAPABILITY, $config);

        // 创建并返回SeleniumManager实例
        return new SeleniumManager(
            host: $this->host,
            capabilities: $capabilities,
            options: [
                'timeout' => 300,
                'request_timeout' => 300,
            ]
        );
    }

    /**
     * 主要处理逻辑
     * @return void
     */
    public function handle()
    {
        try {
            // 打开BOSS直聘登录页面
            $this->openLoginPage();

            // 切换到二维码登录
            $this->switchToQRLogin();

            // 等待用户扫码登录
            $this->waitForScanLogin();

            // 进入消息页面并处理消息
            $this->processMessages();
        } catch (\Exception $e) {
            $this->logger->error("发生错误: " . $e->getMessage());
        }
    }

    /**
     * 打开登录页面
     * @return void
     * @throws NoSuchElementException
     * @throws TimeoutException
     */
    private function openLoginPage(): void
    {
        $this->client->request('GET', 'https://www.zhipin.com/web/user/?ka=header-login');
        $this->client->waitFor('.login-register-content');
        $this->client->takeScreenshot($this->takeScreenshotPath("/login.zhipin.com.png"));
        $this->logger->info("打开BOSS直聘登录页面成功");
    }

    /**
     * 切换到二维码登录
     * @return void
     * @throws NoSuchElementException
     * @throws TimeoutException
     */
    private function switchToQRLogin(): void
    {
        $this->client->waitFor('.btn-sign-switch.ewm-switch');
        $btn = $this->client->findElement(WebDriverBy::cssSelector('.btn-sign-switch.ewm-switch'));
        $this->client->wait()->until(WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::cssSelector('.btn-sign-switch.ewm-switch')));
        $btn->click();
        $this->client->waitFor('.qr-code-box .qr-img-box img', 30);
        $qrpath = $this->takeScreenshotPath("/qr.zhipin.com.png");
        $this->client->takeScreenshot($qrpath);
        $this->logger->info("请在60秒内扫描二维码登录，二维码路径：{$qrpath}");
    }

    /**
     * 等待用户扫码登录
     * @return void
     * @throws NoSuchElementException
     * @throws TimeoutException
     */
    private function waitForScanLogin(): void
    {
        try {
            $this->client->waitFor('.login-step-title', 60);
            $title = $this->client->findElement(WebDriverBy::cssSelector('.login-step-title'));
            $this->logger->info("扫码状态：" . $title->getText());
        } catch (NoSuchElementException|TimeoutException $e) {
            $this->logger->warning("等待扫码超时或未找到元素");
        }

        $this->client->waitFor('.job-recommend-main', 30);
        $this->logger->info("BOSS直聘登录成功，当前URL：" . $this->client->getCurrentURL());
        $this->client->takeScreenshot($this->takeScreenshotPath("/home.zhipin.com.png"));
    }

    /**
     * 处理消息
     * @return void
     */
    private function processMessages(): void
    {
        $this->logger->info("开始获取消息列表");
        $this->client->request('GET', 'https://www.zhipin.com/web/geek/chat?ka=header-message');
        $this->client->waitFor('.user-list-content');
        $this->client->takeScreenshot($this->takeScreenshotPath("/message.zhipin.com.png"));

        while (true) {

            $userListContent = $this->client->findElement(WebDriverBy::cssSelector('.user-list-content'));
            $ulGroup = $userListContent->findElement(WebDriverBy::xpath('.//ul[@role="group"]'));
            $liElements = $ulGroup->findElements(WebDriverBy::tagName('li'));

            foreach ($liElements as $li) {
                $messageInfo = $this->extractMessageInfo($li);
                $this->logMessageInfo($messageInfo);

                if ($messageInfo['unread_count'] > 0) {
                    $this->replyToMessage($li, $messageInfo['name']);
                }
            }

            sleep(5);  // 等待5秒后再次检查新消息
        }
    }

    /**
     * 提取消息信息
     * @param \Facebook\WebDriver\WebDriverElement $li
     * @return array
     * @throws NoSuchElementException
     */
    private function extractMessageInfo(\Facebook\WebDriver\WebDriverElement $li): array
    {
        $result = [];
        $result['unread_count'] = $this->getUnreadCount($li);
        $result['time'] = $li->findElement(WebDriverBy::cssSelector('.time'))->getText();
        $result['name'] = $li->findElement(WebDriverBy::cssSelector('.name-text'))->getText();
        $result['company'] = $li->findElement(WebDriverBy::xpath('.//span[@class="name-box"]/span[2]'))->getText();
        $result['position'] = $li->findElement(WebDriverBy::xpath('.//span[@class="name-box"]/span[last()]'))->getText();
        $result['last_message'] = $li->findElement(WebDriverBy::cssSelector('.last-msg-text'))->getText();
        return $result;
    }

    /**
     * 获取未读消息数量
     * @param \Facebook\WebDriver\WebDriverElement $li
     * @return int
     */
    private function getUnreadCount(\Facebook\WebDriver\WebDriverElement $li): int
    {
        try {
            $noticeBadge = $li->findElement(WebDriverBy::cssSelector('.notice-badge'));
            return (int) $noticeBadge->getText();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * 记录消息信息
     *
     * @param array $messageInfo
     */
    private function logMessageInfo(array $messageInfo): void
    {
        $this->logger->info("消息信息:", $messageInfo);
    }

    /**
     * 回复消息
     * @param \Facebook\WebDriver\WebDriverElement $li
     * @param string $name
     * @return void
     * @throws NoSuchElementException
     * @throws TimeoutException
     */
    private function replyToMessage(\Facebook\WebDriver\WebDriverElement $li, string $name): void
    {
        $this->logger->info("准备回复 {$name} 的未读消息");
        $li->click();

        $this->client->waitFor('.message-content');
        $input = $this->client->findElement(WebDriverBy::id('chat-input'));
        $input->click();
        $input->clear();
        $message = "您好，很高兴收到您的消息。我正在查看您的信息，稍后会给您详细回复。";
        $input->sendKeys($message);

        //                        // 模拟输入文本然后按 Enter
        //                       $input->sendKeys("Your text here" . WebDriverKeys::ENTER);

        $btn = $this->client->findElement(WebDriverBy::cssSelector('.chat-op .btn-v2.btn-sure-v2.btn-send'));
        if ($btn->isDisplayed() && $btn->isEnabled()) {
            $btn->click();
            $this->logger->info("成功回复 {$name} 的消息: {$message}");
        } else {
            $this->logger->warning("回复 {$name} 的消息失败: 发送按钮不可点击");
        }

        sleep(2);  // 等待消息发送完成
    }

    /**
     * 生成截图保存路径
     *
     * @param string $name
     * @return string
     */
    public function takeScreenshotPath(string $name): string
    {
        return __DIR__ . "/screenshot/boss/{$name}";
    }
}