<?php
/**
 * Copyright (C) 2017-2024 thirty bees
 * Copyright (C) 2007-2016 PrestaShop SA
 *
 * thirty bees is an extension to the PrestaShop software by PrestaShop SA.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@thirtybees.com so we can send you a copy immediately.
 *
 * @author    thirty bees <modules@thirtybees.com>
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2017-2024 thirty bees
 * @copyright 2007-2016 PrestaShop SA
 * @license   Academic Free License (AFL 3.0)
 * PrestaShop is an internationally registered trademark of PrestaShop SA.
 */

if (!defined('_TB_VERSION_')) {
    exit;
}

class Blocknewsletter extends Module
{
    const GUEST_NOT_REGISTERED = -1;
    const CUSTOMER_NOT_REGISTERED = 0;
    const GUEST_REGISTERED = 1;
    const CUSTOMER_REGISTERED = 2;

    const ACTION_SUBSCRIBE = '0';
    const ACTION_UNSUBSCRIBE = '1';

    const SETTINGS_KEY_CAPTCHA = 'PS_NEWSLETTER_CAPTCHA';

    /**
     * @var bool
     */
    protected $error;

    /**
     * @var string|false
     */
    protected $valid;

    /**
     * @var string|null
     */
    protected $_searched_email;

    /**
     * @var string
     */
    protected $_html;

    /**
     * @var string
     */
    protected $file;

    /**
     * @var HelperList
     */
    protected $_helperlist;

    /**
     * @throws PrestaShopException
     */
    public function __construct()
    {
        $this->name = 'blocknewsletter';
        $this->tab = 'front_office_features';
        $this->need_instance = 0;

        $this->controllers = ['verification'];

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->l('Block Newsletter');
        $this->description = $this->l('Adds a block for newsletter subscription.');
        $this->confirmUninstall = $this->l('Are you sure that you want to delete all of your contacts?');
        $this->tb_versions_compliancy = '> 1.0.0';
        $this->tb_min_version = '1.0.0';
        $this->ps_versions_compliancy = ['min' => '1.6', 'max' => '1.6.99.99'];

        $this->version = '3.2.0';
        $this->author = 'thirty bees';
        $this->error = false;
        $this->valid = false;

        $this->_searched_email = null;

        $this->_html = '';
        if ($this->id) {
            $this->file = 'export_' . Configuration::get('PS_NEWSLETTER_RAND') . '.csv';
        }
    }

    /**
     * @return bool
     * @throws PrestaShopException
     */
    public function install()
    {
        if (!parent::install() || !Configuration::updateValue('PS_NEWSLETTER_RAND', rand() . rand()) || !$this->registerHook(['header', 'footer', 'actionCustomerAccountAdd'])) {
            return false;
        }

        Configuration::updateValue('NW_SALT', Tools::passwdGen(16));

        return Db::getInstance()->execute('
		CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'newsletter` (
			`id` int(6) NOT NULL AUTO_INCREMENT,
			`id_shop` INTEGER UNSIGNED NOT NULL DEFAULT \'1\',
			`id_shop_group` INTEGER UNSIGNED NOT NULL DEFAULT \'1\',
			`email` varchar(255) NOT NULL,
			`newsletter_date_add` DATETIME NULL,
			`ip_registration_newsletter` varchar(15) NOT NULL,
			`http_referer` VARCHAR(255) NULL,
			`active` TINYINT(1) NOT NULL DEFAULT \'0\',
			PRIMARY KEY(`id`)
		) ENGINE=' . _MYSQL_ENGINE_ . ' default CHARSET=utf8');
    }

    /**
     * @return bool
     * @throws PrestaShopException
     */
    public function uninstall()
    {
        Db::getInstance()->execute('DROP TABLE ' . _DB_PREFIX_ . 'newsletter');

        return parent::uninstall();
    }

    /**
     * @return string
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function getContent()
    {
        if (Tools::isSubmit('submitUpdate')) {
            Configuration::updateValue('NW_CONFIRMATION_EMAIL', (bool)Tools::getValue('NW_CONFIRMATION_EMAIL'));
            Configuration::updateValue('NW_VERIFICATION_EMAIL', (bool)Tools::getValue('NW_VERIFICATION_EMAIL'));
            Configuration::updateValue(static::SETTINGS_KEY_CAPTCHA, Tools::getValue(static::SETTINGS_KEY_CAPTCHA, 'none'));

            $voucher = Tools::getValue('NW_VOUCHER_CODE');
            if ($voucher && !Validate::isDiscountName($voucher)) {
                $this->_html .= $this->displayError($this->l('The voucher code is invalid.'));
            } else {
                Configuration::updateValue('NW_VOUCHER_CODE', $voucher);
                $this->_html .= $this->displayConfirmation($this->l('Settings updated'));
            }
        } elseif (Tools::isSubmit('subscribedmerged')) {
            $id = Tools::getValue('id');

            if (preg_match('/(^N)/', $id)) {
                $id = (int)substr($id, 1);
                $sql = 'UPDATE ' . _DB_PREFIX_ . 'newsletter SET active = 0 WHERE id = ' . $id;
                Db::getInstance()->execute($sql);
            } else {
                $c = new Customer((int)$id);
                $c->newsletter = (int)!$c->newsletter;
                $c->update();
            }
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&conf=4&token=' . Tools::getAdminTokenLite('AdminModules'));

        } elseif (Tools::isSubmit('submitExport') && Tools::getValue('action')) {
            $this->export_csv();
        } elseif (Tools::isSubmit('searchEmail')) {
            $this->_searched_email = Tools::getValue('searched_email');
        }

        $this->_html .= $this->renderForm();
        $this->_html .= $this->renderSearchForm();
        $this->_html .= $this->renderList();

        $this->_html .= $this->renderExportForm();

        return $this->_html;
    }

    /**
     * @return false|string
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function renderList()
    {
        $fields_list = [

            'id' => [
                'title' => $this->l('ID'),
                'search' => false,
            ],
            'shop_name' => [
                'title' => $this->l('Shop'),
                'search' => false,
            ],
            'gender' => [
                'title' => $this->l('Gender'),
                'search' => false,
            ],
            'lastname' => [
                'title' => $this->l('Lastname'),
                'search' => false,
            ],
            'firstname' => [
                'title' => $this->l('Firstname'),
                'search' => false,
            ],
            'email' => [
                'title' => $this->l('Email'),
                'search' => false,
            ],
            'subscribed' => [
                'title' => $this->l('Subscribed'),
                'type' => 'bool',
                'active' => 'subscribed',
                'search' => false,
            ],
            'newsletter_date_add' => [
                'title' => $this->l('Subscribed on'),
                'type' => 'date',
                'search' => false,
            ]
        ];

        if (!Configuration::get('PS_MULTISHOP_FEATURE_ACTIVE')) {
            unset($fields_list['shop_name']);
        }

        $helper_list = new HelperList();
        $helper_list->module = $this;
        $helper_list->title = $this->l('Newsletter registrations');
        $helper_list->shopLinkType = '';
        $helper_list->no_link = true;
        $helper_list->show_toolbar = true;
        $helper_list->simple_header = false;
        $helper_list->identifier = 'id';
        $helper_list->table = 'merged';
        $helper_list->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name;
        $helper_list->token = Tools::getAdminTokenLite('AdminModules');
        $helper_list->actions = ['viewCustomer'];

        // This is needed for displayEnableLink to avoid code duplication
        $this->_helperlist = $helper_list;

        /* Retrieve list data */
        $subscribers = $this->getSubscribers();
        $helper_list->listTotal = count($subscribers);

        /* Paginate the result */
        $page = ($page = Tools::getValue('submitFilter' . $helper_list->table)) ? $page : 1;
        $pagination = ($pagination = Tools::getValue($helper_list->table . '_pagination')) ? $pagination : 50;
        $subscribers = $this->paginateSubscribers($subscribers, $page, $pagination);

        return $helper_list->generateList($subscribers, $fields_list);
    }

    /**
     * @param string|null $token
     * @param int|null $id
     * @param string|null $name
     *
     * @return string
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function displayViewCustomerLink($token = null, $id = null, $name = null)
    {
        $this->smarty->assign([
            'href' => 'index.php?controller=AdminCustomers&id_customer=' . (int)$id . '&updatecustomer&token=' . Tools::getAdminTokenLite('AdminCustomers'),
            'action' => $this->l('View'),
            'disable' => !((int)$id > 0),
        ]);

        return $this->display(__FILE__, 'views/templates/admin/list_action_viewcustomer.tpl');
    }

    /**
     * @param string $token
     * @param int $id
     * @param bool $value
     * @param bool $active
     * @param int|null $id_category
     * @param int|null $id_product
     * @param bool $ajax
     *
     * @return string
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function displayEnableLink($token, $id, $value, $active, $id_category = null, $id_product = null, $ajax = false)
    {
        $this->smarty->assign([
            'ajax' => $ajax,
            'enabled' => (bool)$value,
            'url_enable' => $this->_helperlist->currentIndex . '&' . $this->_helperlist->identifier . '=' . $id . '&' . $active . $this->_helperlist->table . ($ajax ? '&action=' . $active . $this->_helperlist->table . '&ajax=' . (int)$ajax : '') . ((int)$id_category && (int)$id_product ? '&id_category=' . (int)$id_category : '') . '&token=' . $token
        ]);

        return $this->display(__FILE__, 'views/templates/admin/list_action_enable.tpl');
    }

    /**
     * @param string|null $token
     * @param int|null $id
     * @param string|null $name
     *
     * @return string
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function displayUnsubscribeLink($token = null, $id = null, $name = null)
    {
        $this->smarty->assign([
            'href' => $this->_helperlist->currentIndex . '&subscribedcustomer&' . $this->_helperlist->identifier . '=' . $id . '&token=' . $token,
            'action' => $this->l('Unsubscribe'),
        ]);

        return $this->display(__FILE__, 'views/templates/admin/list_action_unsubscribe.tpl');
    }

    /**
     * Check if this mail is registered for newsletters
     *
     * @param string $customer_email
     *
     * @return int -1 = not a customer and not registered
     *                0 = customer not registered
     *                1 = registered in block
     *                2 = registered in customer
     *
     * @throws PrestaShopException
     */
    public function isNewsletterRegistered($customer_email)
    {
        $sql = 'SELECT `email`
				FROM ' . _DB_PREFIX_ . 'newsletter
				WHERE `email` = \'' . pSQL($customer_email) . '\'
				AND id_shop = ' . $this->context->shop->id;

        if (Db::getInstance()->getRow($sql)) {
            return self::GUEST_REGISTERED;
        }

        $sql = 'SELECT `newsletter`
				FROM ' . _DB_PREFIX_ . 'customer
				WHERE `email` = \'' . pSQL($customer_email) . '\'
				AND id_shop = ' . $this->context->shop->id;

        if (!$registered = Db::getInstance()->getRow($sql)) {
            return self::GUEST_NOT_REGISTERED;
        }

        if ($registered['newsletter'] == '1') {
            return self::CUSTOMER_REGISTERED;
        }

        return self::CUSTOMER_NOT_REGISTERED;
    }

    /**
     * Register in block newsletter
     *
     * @return boolean
     * @throws PrestaShopException
     */
    protected function newsletterRegistration()
    {
        if ($captchaError = $this->validateCaptcha()) {
            $this->error = $captchaError;
            return false;
        }
        $email = Tools::getValue('email');
        $action = Tools::getValue('action');

        if (empty($email) || !Validate::isEmail($email)) {
            $this->error = $this->l('Invalid email address.');
            return false;
        }

        switch ($action) {
            case static::ACTION_SUBSCRIBE:
                return $this->subscribe($email);
            case static::ACTION_UNSUBSCRIBE:
                return $this->unsubscribe($email);
            default:
                return false;
        }
    }

    /**
     * @return array
     * @throws PrestaShopException
     */
    public function getSubscribers()
    {
        $dbquery = new DbQuery();
        $dbquery->select('c.`id_customer` AS `id`, s.`name` AS `shop_name`, gl.`name` AS `gender`, c.`lastname`, c.`firstname`, c.`email`, c.`newsletter` AS `subscribed`, c.`newsletter_date_add`');
        $dbquery->from('customer', 'c');
        $dbquery->leftJoin('shop', 's', 's.id_shop = c.id_shop');
        $dbquery->leftJoin('gender', 'g', 'g.id_gender = c.id_gender');
        $dbquery->leftJoin('gender_lang', 'gl', 'g.id_gender = gl.id_gender AND gl.id_lang = ' . (int)$this->context->employee->id_lang);
        $dbquery->where('c.`newsletter` = 1');
        if ($this->_searched_email) {
            $dbquery->where('c.`email` LIKE \'%' . pSQL($this->_searched_email) . '%\' ');
        }

        $customers = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($dbquery->build());

        $dbquery = new DbQuery();
        $dbquery->select('CONCAT(\'N\', n.`id`) AS `id`, s.`name` AS `shop_name`, NULL AS `gender`, NULL AS `lastname`, NULL AS `firstname`, n.`email`, n.`active` AS `subscribed`, n.`newsletter_date_add`');
        $dbquery->from('newsletter', 'n');
        $dbquery->leftJoin('shop', 's', 's.id_shop = n.id_shop');
        $dbquery->where('n.`active` = 1');
        if ($this->_searched_email) {
            $dbquery->where('n.`email` LIKE \'%' . pSQL($this->_searched_email) . '%\' ');
        }

        $non_customers = Db::getInstance()->executeS($dbquery->build());

        $subscribers = array_merge($customers, $non_customers);

        return $subscribers;
    }

    /**
     * @param array $subscribers
     * @param int $page
     * @param int $pagination
     *
     * @return array
     */
    public function paginateSubscribers($subscribers, $page = 1, $pagination = 50)
    {
        if (count($subscribers) > $pagination) {
            $subscribers = array_slice($subscribers, $pagination * ($page - 1), $pagination);
        }

        return $subscribers;
    }

    /**
     * Return true if the registered status correspond to a registered user
     *
     * @param int $register_status
     *
     * @return bool
     */
    protected function isRegistered($register_status)
    {
        return in_array(
            $register_status,
            [self::GUEST_REGISTERED, self::CUSTOMER_REGISTERED]
        );
    }


    /**
     * Subscribe an email to the newsletter. It will create an entry in the newsletter table
     * or update the customer table depending of the register status
     *
     * @param string $email
     * @param int $register_status
     *
     * @throws PrestaShopException
     * @throws PrestaShopException
     */
    protected function register($email, $register_status)
    {
        if ($register_status == self::GUEST_NOT_REGISTERED) {
            return $this->registerGuest($email);
        }

        if ($register_status == self::CUSTOMER_NOT_REGISTERED) {
            return $this->registerUser($email);
        }

        return false;
    }

    /**
     * @param string $email
     * @param int $registerStatus
     *
     * @return bool
     * @throws PrestaShopException
     */
    protected function unregister($email, $registerStatus)
    {
        if ($registerStatus == self::GUEST_REGISTERED) {
            $sql = 'DELETE FROM ' . _DB_PREFIX_ . 'newsletter WHERE `email` = \'' . pSQL($email) . '\' AND id_shop = ' . $this->context->shop->id;
        } else {
            if ($registerStatus == self::CUSTOMER_REGISTERED) {
                $sql = 'UPDATE ' . _DB_PREFIX_ . 'customer SET `newsletter` = 0 WHERE `email` = \'' . pSQL($email) . '\' AND id_shop = ' . $this->context->shop->id;
            }
        }

        if (!isset($sql) || !Db::getInstance()->execute($sql)) {
            return false;
        }

        return true;
    }

    /**
     * Subscribe a customer to the newsletter
     *
     * @param string $email
     *
     * @return bool
     * @throws PrestaShopException
     */
    protected function registerUser($email)
    {
        $sql = 'UPDATE ' . _DB_PREFIX_ . 'customer
				SET `newsletter` = 1, newsletter_date_add = NOW(), `ip_registration_newsletter` = \'' . pSQL(Tools::getRemoteAddr()) . '\'
				WHERE `email` = \'' . pSQL($email) . '\'
				AND id_shop = ' . $this->context->shop->id;

        return Db::getInstance()->execute($sql);
    }

    /**
     * Subscribe a guest to the newsletter
     *
     * @param string $email
     * @param bool $active
     *
     * @return bool
     * @throws PrestaShopException
     */
    protected function registerGuest($email, $active = true)
    {
        $sql = 'INSERT INTO ' . _DB_PREFIX_ . 'newsletter (id_shop, id_shop_group, email, newsletter_date_add, ip_registration_newsletter, http_referer, active)
				VALUES
				(' . $this->context->shop->id . ',
				' . $this->context->shop->id_shop_group . ',
				\'' . pSQL($email) . '\',
				NOW(),
				\'' . pSQL(Tools::getRemoteAddr()) . '\',
				(
					SELECT c.http_referer
					FROM ' . _DB_PREFIX_ . 'connections c
					WHERE c.id_guest = ' . (int)$this->context->customer->id . '
					ORDER BY c.date_add DESC LIMIT 1
				),
				' . (int)$active . '
				)';

        return Db::getInstance()->execute($sql);
    }


    /**
     * @param string $email
     *
     * @return bool
     * @throws PrestaShopException
     */
    public function activateGuest($email)
    {
        return Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'newsletter`
						SET `active` = 1
						WHERE `email` = \'' . pSQL($email) . '\''
        );
    }

    /**
     * Returns a guest email by token
     *
     * @param string $token
     *
     * @return string email
     * @throws PrestaShopException
     */
    protected function getGuestEmailByToken($token)
    {
        $sql = 'SELECT `email`
				FROM `' . _DB_PREFIX_ . 'newsletter`
				WHERE MD5(CONCAT( `email` , `newsletter_date_add`, \'' . Configuration::get('NW_SALT') . '\')) = \'' . pSQL($token) . '\'
				AND `active` = 0';

        return Db::getInstance()->getValue($sql);
    }

    /**
     * Returns a customer email by token
     *
     * @param string $token
     *
     * @return string email
     * @throws PrestaShopException
     */
    protected function getUserEmailByToken($token)
    {
        $sql = 'SELECT `email`
				FROM `' . _DB_PREFIX_ . 'customer`
				WHERE MD5(CONCAT( `email` , `date_add`, \'' . Configuration::get('NW_SALT') . '\')) = \'' . pSQL($token) . '\'
				AND `newsletter` = 0';

        return Db::getInstance()->getValue($sql);
    }

    /**
     * Return a token associated with user
     *
     * @param string $email
     * @param string $register_status
     *
     * @return false | string
     * @throws PrestaShopException
     */
    protected function getToken($email, $register_status)
    {
        if (in_array($register_status, [self::GUEST_NOT_REGISTERED, self::GUEST_REGISTERED])) {
            $sql = 'SELECT MD5(CONCAT( `email` , `newsletter_date_add`, \'' . Configuration::get('NW_SALT') . '\')) as token
					FROM `' . _DB_PREFIX_ . 'newsletter`
					WHERE `active` = 0
					AND `email` = \'' . pSQL($email) . '\'';
            return Db::getInstance()->getValue($sql);
        } else {
            if ($register_status == self::CUSTOMER_NOT_REGISTERED) {
                $sql = 'SELECT MD5(CONCAT( `email` , `date_add`, \'' . Configuration::get('NW_SALT') . '\' )) as token
					FROM `' . _DB_PREFIX_ . 'customer`
					WHERE `newsletter` = 0
					AND `email` = \'' . pSQL($email) . '\'';
                return Db::getInstance()->getValue($sql);
            }
        }
        return false;
    }

    /**
     * Ends the registration process to the newsletter
     *
     * @param string $token
     *
     * @return string
     * @throws PrestaShopException
     */
    public function confirmEmail($token)
    {
        $activated = false;

        if ($email = $this->getGuestEmailByToken($token)) {
            $activated = $this->activateGuest($email);
        } else {
            if ($email = $this->getUserEmailByToken($token)) {
                $activated = $this->registerUser($email);
            }
        }

        if (!$activated) {
            return $this->l('This email is already registered and/or invalid.');
        }

        if ($discount = Configuration::get('NW_VOUCHER_CODE')) {
            $this->sendVoucher($email, $discount);
        }

        if (Configuration::get('NW_CONFIRMATION_EMAIL')) {
            $this->sendConfirmationEmail($email);
        }

        return $this->l('Thank you for subscribing to our newsletter.');
    }

    /**
     * Send the confirmation mails to the given $email address if needed.
     *
     * @param string $email Email where to send the confirmation
     *
     * @throws PrestaShopException
     * @note the email has been verified and might not yet been registered. Called by AuthController::processCustomerNewsletter
     */
    public function confirmSubscription($email)
    {
        if ($email) {
            if ($discount = Configuration::get('NW_VOUCHER_CODE')) {
                $this->sendVoucher($email, $discount);
            }

            if (Configuration::get('NW_CONFIRMATION_EMAIL')) {
                $this->sendConfirmationEmail($email);
            }
        }
    }

    /**
     * Send an email containing a voucher code
     *
     * @param string $email
     * @param string $code
     *
     * @return bool
     * @throws PrestaShopException
     */
    protected function sendVoucher($email, $code)
    {
        return Mail::Send($this->context->language->id, 'newsletter_voucher', Mail::l('Newsletter voucher', $this->context->language->id), ['{discount}' => $code], $email, null, null, null, null, null, dirname(__FILE__) . '/mails/', false, $this->context->shop->id);
    }

    /**
     * Send a confirmation email
     *
     * @param string $email
     *
     * @return bool
     * @throws PrestaShopException
     */
    protected function sendConfirmationEmail($email)
    {
        return Mail::Send($this->context->language->id, 'newsletter_conf', Mail::l('Newsletter confirmation', $this->context->language->id), [], pSQL($email), null, null, null, null, null, dirname(__FILE__) . '/mails/', false, $this->context->shop->id);
    }

    /**
     * Send a verification email
     *
     * @param string $email
     * @param string $token
     *
     * @return bool
     * @throws PrestaShopException
     */
    protected function sendVerificationEmail($email, $token)
    {
        $verif_url = Context::getContext()->link->getModuleLink(
            'blocknewsletter', 'verification', [
                'token' => $token,
            ]
        );

        return Mail::Send($this->context->language->id, 'newsletter_verif', Mail::l('Email verification', $this->context->language->id), ['{verif_url}' => $verif_url], $email, null, null, null, null, null, dirname(__FILE__) . '/mails/', false, $this->context->shop->id);
    }

    /**
     * @param array $params
     *
     * @return string
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function hookDisplayRightColumn($params)
    {
        return $this->hookDisplayLeftColumn($params);
    }

    /**
     * @param array $params
     *
     * @return void
     *
     * @throws PrestaShopException
     */
    protected function _prepareHook($params)
    {
        if ($_SERVER['REQUEST_METHOD'] == 'POST' && Tools::isSubmit('submitNewsletter')) {
            $this->newsletterRegistration();
            if ($this->error) {
                $this->smarty->assign(
                    [
                        'color' => 'red',
                        'msg' => $this->error,
                        'nw_error' => true,
                        'action' => Tools::getValue('action')
                    ]
                );
            } else {
                if ($this->valid) {
                    $this->smarty->assign(
                        [
                            'color' => 'green',
                            'msg' => $this->valid,
                            'nw_error' => false
                        ]
                    );
                }
            }
        }
        $this->smarty->assign([
            'this_path', $this->_path,
            'newsletterCaptcha' => (string)$this->renderCaptcha()
        ]);

    }

    /**
     * @param array $params
     *
     * @return string
     *
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function hookDisplayLeftColumn($params)
    {
        $this->_prepareHook($params);
        return $this->display(__FILE__, 'blocknewsletter.tpl');
    }

    /**
     * @param array $params
     *
     * @return string
     *
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function hookFooter($params)
    {
        return $this->hookDisplayLeftColumn($params);
    }

    /**
     * @param array $params
     *
     * @return string
     *
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function hookdisplayMaintenance($params)
    {
        return $this->hookDisplayLeftColumn($params);
    }

    /**
     * @param array $params
     *
     * @return void
     */
    public function hookDisplayHeader($params)
    {
        $this->context->controller->addCSS($this->_path . 'blocknewsletter.css', 'all');
        $this->context->controller->addJS($this->_path . 'blocknewsletter.js');
    }

    /**
     * Deletes duplicates email in newsletter table
     *
     * @param array $params
     *
     * @return bool
     * @throws PrestaShopException
     */
    public function hookActionCustomerAccountAdd($params)
    {
        //if e-mail of the created user address has already been added to the newsletter through the blocknewsletter module,
        //we delete it from blocknewsletter table to prevent duplicates
        $id_shop = $params['newCustomer']->id_shop;
        $email = $params['newCustomer']->email;
        if (Validate::isEmail($email)) {
            return (bool)Db::getInstance()->execute('DELETE FROM ' . _DB_PREFIX_ . 'newsletter WHERE id_shop=' . (int)$id_shop . ' AND email=\'' . pSQL($email) . "'");
        }

        return true;
    }

    /**
     * @return string
     *
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function renderForm()
    {
        $captchas = [
            [
                'id' => '',
                'name' => $this->l('Don\'t use captcha')
            ]
        ];
        foreach ($this->getCaptchaImplementations() as $id => $captcha) {
            $captchas[] = [
                'id' => $id,
                'name' => $captcha['name']
            ];
        }

        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs'
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Would you like to send a verification email after subscription?'),
                        'name' => 'NW_VERIFICATION_EMAIL',
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Yes')
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('No')
                            ]
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Would you like to send a confirmation email after subscription?'),
                        'name' => 'NW_CONFIRMATION_EMAIL',
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Yes')
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('No')
                            ]
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Welcome voucher code'),
                        'name' => 'NW_VOUCHER_CODE',
                        'class' => 'fixed-width-md',
                        'desc' => $this->l('Leave blank to disable by default.')
                    ],
                    [
                        'type'    => 'select',
                        'label'   => $this->l('Captcha'),
                        'name'    => static::SETTINGS_KEY_CAPTCHA,
                        'options' => [
                            'query' => $captchas,
                            'id'    => 'id',
                            'name'  => 'name',
                        ],
                        'desc' => (count($captchas) === 1)
                            ? $this->l('Warning: no supported captcha modules are currently installed')
                            : ''
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                ]
            ],
        ];

        /** @var AdminController $controller */
        $controller = $this->context->controller;

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitUpdate';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $controller->getLanguages(),
            'id_language' => $this->context->language->id
        ];

        return $helper->generateForm([$fields_form]);
    }

    /**
     * @return string
     *
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function renderExportForm()
    {
        // Getting data...
        $countries = Country::getCountries($this->context->language->id);

        // ...formatting array
        $countries_list = [['id' => 0, 'name' => $this->l('All countries')]];
        foreach ($countries as $country) {
            $countries_list[] = ['id' => $country['id_country'], 'name' => $country['name']];
        }

        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Export customers\' addresses'),
                    'icon' => 'icon-envelope'
                ],
                'input' => [
                    [
                        'type' => 'select',
                        'label' => $this->l('Customers\' country'),
                        'desc' => $this->l('Filter customers by country.'),
                        'name' => 'COUNTRY',
                        'required' => false,
                        'default_value' => (int)$this->context->country->id,
                        'options' => [
                            'query' => $countries_list,
                            'id' => 'id',
                            'name' => 'name',
                        ]
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Newsletter subscribers'),
                        'desc' => $this->l('Filter customers who have subscribed to the newsletter or not, and who have an account or not.'),
                        'hint' => $this->l('Customers can subscribe to your newsletter when registering, or by entering their email in the newsletter block.'),
                        'name' => 'SUSCRIBERS',
                        'required' => false,
                        'default_value' => (int)$this->context->country->id,
                        'options' => [
                            'query' => [
                                ['id' => 0, 'name' => $this->l('All subscribers')],
                                ['id' => 1, 'name' => $this->l('Subscribers with account')],
                                ['id' => 2, 'name' => $this->l('Subscribers without account')],
                                ['id' => 3, 'name' => $this->l('Non-subscribers')]
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ]
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Opt-in subscribers'),
                        'desc' => $this->l('Filter customers who have agreed to receive your partners\' offers or not.'),
                        'hint' => $this->l('Opt-in subscribers have agreed to receive your partners\' offers.'),
                        'name' => 'OPTIN',
                        'required' => false,
                        'default_value' => (int)$this->context->country->id,
                        'options' => [
                            'query' => [
                                ['id' => 0, 'name' => $this->l('All customers')],
                                ['id' => 2, 'name' => $this->l('Opt-in subscribers')],
                                ['id' => 1, 'name' => $this->l('Opt-in non-subscribers')]
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ]
                    ],
                    [
                        'type' => 'hidden',
                        'name' => 'action',
                    ]
                ],
                'submit' => [
                    'title' => $this->l('Export .CSV file'),
                    'class' => 'btn btn-default pull-right',
                    'name' => 'submitExport',
                ]
            ],
        ];

        /** @var AdminController $controlelr */
        $controller = $this->context->controller;

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->id = (int)Tools::getValue('id_carrier');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'btnSubmit';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $controller->getLanguages(),
            'id_language' => $this->context->language->id
        ];

        return $helper->generateForm([$fields_form]);
    }

    /**
     * @return string
     *
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function renderSearchForm()
    {
        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Search for addresses'),
                    'icon' => 'icon-search'
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('Email address to search'),
                        'name' => 'searched_email',
                        'class' => 'fixed-width-xxl',
                        'desc' => $this->l('Example: contact@prestashop.com or @prestashop.com')
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Search'),
                    'icon' => 'process-icon-refresh',
                ]
            ],
        ];

        /** @var AdminController $controller */
        $controller = $this->context->controller;

        $helper = new HelperForm();
        $helper->table = $this->table;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'searchEmail';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => ['searched_email' => $this->_searched_email],
            'languages' => $controller->getLanguages(),
            'id_language' => $this->context->language->id
        ];

        return $helper->generateForm([$fields_form]);
    }

    /**
     * @return array
     * @throws PrestaShopException
     */
    public function getConfigFieldsValues()
    {
        return [
            static::SETTINGS_KEY_CAPTCHA => Tools::getValue(static::SETTINGS_KEY_CAPTCHA, $this->getSelectedCaptchaKey()),
            'NW_VERIFICATION_EMAIL' => Tools::getValue('NW_VERIFICATION_EMAIL', Configuration::get('NW_VERIFICATION_EMAIL')),
            'NW_CONFIRMATION_EMAIL' => Tools::getValue('NW_CONFIRMATION_EMAIL', Configuration::get('NW_CONFIRMATION_EMAIL')),
            'NW_VOUCHER_CODE' => Tools::getValue('NW_VOUCHER_CODE', Configuration::get('NW_VOUCHER_CODE')),
            'COUNTRY' => Tools::getValue('COUNTRY'),
            'SUSCRIBERS' => Tools::getValue('SUSCRIBERS'),
            'OPTIN' => Tools::getValue('OPTIN'),
            'action' => 'customers',
        ];
    }

    /**
     * @return void
     * @throws PrestaShopException
     */
    public function export_csv()
    {
        if (!isset($this->context)) {
            $this->context = Context::getContext();
        }

        $result = $this->getCustomers();

        if ($result) {
            if (!$nb = count($result)) {
                $this->_html .= $this->displayError($this->l('No customers found with these filters!'));
            } elseif ($fd = @fopen(dirname(__FILE__) . '/' . preg_replace('#\.{2,}#', '.', Tools::getValue('action')) . '_' . $this->file, 'w')) {
                $header = ['id', 'shop_name', 'gender', 'lastname', 'firstname', 'email', 'subscribed', 'subscribed_on'];
                $array_to_export = array_merge([$header], $result);
                foreach ($array_to_export as $tab) {
                    $this->myFputCsv($fd, $tab);
                }
                fclose($fd);
                $this->_html .= $this->displayConfirmation(
                    sprintf($this->l('The .CSV file has been successfully exported: %d customers found.'), $nb) . '<br />
				<a href="' . $this->context->shop->getBaseURI() . 'modules/blocknewsletter/' . Tools::safeOutput(strval(Tools::getValue('action'))) . '_' . $this->file . '">
				<b>' . $this->l('Download the file') . ' ' . $this->file . '</b>
				</a>
				<br />
				<ol style="margin-top: 10px;">
					<li style="color: red;">' .
                    $this->l('WARNING: When opening this .csv file with Excel, choose UTF-8 encoding to avoid strange characters.') .
                    '</li>
				</ol>');
            } else {
                $this->_html .= $this->displayError($this->l('Error: Write access limited') . ' ' . dirname(__FILE__) . '/' . Tools::getValue('action') . '_' . $this->file . ' !');
            }
        } else {
            $this->_html .= $this->displayError($this->l('No result found!'));
        }
    }

    /**
     * @return array
     * @throws PrestaShopException
     */
    private function getCustomers()
    {
        $id_shop = false;

        // Get the value to know with subscrib I need to take 1 with account 2 without 0 both 3 not subscrib
        $who = (int)Tools::getValue('SUSCRIBERS');

        // get optin 0 for all 1 no optin 2 with optin
        $optin = (int)Tools::getValue('OPTIN');

        $country = (int)Tools::getValue('COUNTRY');

        if (Context::getContext()->cookie->shopContext) {
            $id_shop = (int)Context::getContext()->shop->id;
        }

        $customers = [];
        if ($who == 1 || $who == 0 || $who == 3) {
            $dbquery = new DbQuery();
            $dbquery->select('c.`id_customer` AS `id`, s.`name` AS `shop_name`, gl.`name` AS `gender`, c.`lastname`, c.`firstname`, c.`email`, c.`newsletter` AS `subscribed`, c.`newsletter_date_add`');
            $dbquery->from('customer', 'c');
            $dbquery->leftJoin('shop', 's', 's.id_shop = c.id_shop');
            $dbquery->leftJoin('gender', 'g', 'g.id_gender = c.id_gender');
            $dbquery->leftJoin('gender_lang', 'gl', 'g.id_gender = gl.id_gender AND gl.id_lang = ' . $this->context->employee->id_lang);
            $dbquery->where('c.`newsletter` = ' . ($who == 3 ? 0 : 1));
            if ($optin == 2 || $optin == 1) {
                $dbquery->where('c.`optin` = ' . ($optin == 1 ? 0 : 1));
            }
            if ($country) {
                $dbquery->where('(SELECT COUNT(a.`id_address`) as nb_country
													FROM `' . _DB_PREFIX_ . 'address` a
													WHERE a.deleted = 0
													AND a.`id_customer` = c.`id_customer`
													AND a.`id_country` = ' . $country . ') >= 1');
            }
            if ($id_shop) {
                $dbquery->where('c.`id_shop` = ' . $id_shop);
            }

            $customers = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($dbquery->build());
        }

        $non_customers = [];
        if (($who == 0 || $who == 2) && (!$optin || $optin == 2) && !$country) {
            $dbquery = new DbQuery();
            $dbquery->select('CONCAT(\'N\', n.`id`) AS `id`, s.`name` AS `shop_name`, NULL AS `gender`, NULL AS `lastname`, NULL AS `firstname`, n.`email`, n.`active` AS `subscribed`, n.`newsletter_date_add`');
            $dbquery->from('newsletter', 'n');
            $dbquery->leftJoin('shop', 's', 's.id_shop = n.id_shop');
            $dbquery->where('n.`active` = 1');
            if ($id_shop) {
                $dbquery->where('n.`id_shop` = ' . $id_shop);
            }
            $non_customers = Db::getInstance()->executeS($dbquery->build());
        }

        $subscribers = array_merge($customers, $non_customers);

        return $subscribers;
    }

    /**
     * @param resource $fd
     * @param array $array
     *
     * @return void
     */
    private function myFputCsv($fd, $array)
    {
        $line = implode(';', $array);
        $line .= "\n";
        fwrite($fd, $line, 4096);
    }

    /**
     * @param string $email
     *
     * @return bool
     * @throws PrestaShopException
     */
    protected function unsubscribe($email)
    {
        $register_status = $this->isNewsletterRegistered($email);

        if ($register_status < 1) {
            $this->error = $this->l('This email address is not registered.');
            return false;

        }

        if (!$this->unregister($email, $register_status)) {
            $this->error = $this->l('An error occurred while attempting to unsubscribe.');
            return false;
        }

        $this->valid = $this->l('Unsubscription successful.');
        return true;
    }

    /**
     * @param string $email
     *
     * @return bool
     * @throws PrestaShopException
     */
    protected function subscribe($email)
    {
        $registerStatus = $this->isNewsletterRegistered($email);
        if ($registerStatus > 0) {
            $this->error = $this->l('This email address is already registered.');
            return false;
        }

        if (!$this->isRegistered($registerStatus)) {
            if (Configuration::get('NW_VERIFICATION_EMAIL')) {
                // create an inactive entry in the newsletter database
                if ($registerStatus == self::GUEST_NOT_REGISTERED) {
                    $this->registerGuest($email, false);
                }

                if (!$token = $this->getToken($email, $registerStatus)) {
                    $this->error = $this->l('An error occurred during the subscription process.');
                    return false;
                }

                $this->sendVerificationEmail($email, $token);

                $this->valid = $this->l('A verification email has been sent. Please check your inbox.');
                return true;
            } else {
                if ($this->register($email, $registerStatus)) {
                    $this->valid = $this->l('You have successfully subscribed to this newsletter.');

                    if ($code = Configuration::get('NW_VOUCHER_CODE')) {
                        $this->sendVoucher($email, $code);
                    }

                    if (Configuration::get('NW_CONFIRMATION_EMAIL')) {
                        $this->sendConfirmationEmail($email);
                    }

                    return true;
                } else {
                    $this->error = $this->l('An error occurred during the subscription process.');
                    return false;
                }
            }
        }

        return false;
    }

    /**
     * @return array
     * @throws PrestaShopException
     */
    protected function getCaptchaImplementations()
    {
        $implemenentations = [];
        // captcha
        $responses = Hook::exec('actionRegisterCaptcha', [], null, true);
        if (is_array($responses)) {
            foreach ($responses as $module => $response) {
                if (is_array($response) && isset($response['name']) && isset($response['render']) && isset($response['validate'])) {
                    $implemenentations[$module] = $response;
                }
            }
        }
        return $implemenentations;
    }


    /**
     * @return string
     *
     * @throws PrestaShopException
     */
    protected function renderCaptcha()
    {
        // captcha
        $captcha = $this->getSelectedCaptcha();
        if ($captcha) {
            return (string)call_user_func($captcha['render'], $this->name);
        }
        return '';
    }

    /**
     * @return string|null
     * @throws PrestaShopException
     */
    protected function validateCaptcha()
    {
        $captcha = $this->getSelectedCaptcha();
        if ($captcha) {
            $validation = call_user_func($captcha['validate'], $this->name);
            if ($validation === true) {
                return null;
            }
            if ($validation === false) {
                return $this->l('Please solve captcha');
            }
            return (string)$validation;
        }
        return null;
    }

    /**
     * @return false|array
     * @throws PrestaShopException
     */
    protected function getSelectedCaptcha()
    {
        $selected = Configuration::get(static::SETTINGS_KEY_CAPTCHA);
        if ($selected) {
            $captchas = $this->getCaptchaImplementations();
            if (isset($captchas[$selected])) {
                return $captchas[$selected];
            }
        }
        return false;
    }

    /**
     * @return string
     * @throws PrestaShopException
     */
    protected function getSelectedCaptchaKey()
    {
        return (string)Configuration::get(static::SETTINGS_KEY_CAPTCHA);
    }
}
