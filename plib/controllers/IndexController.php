<?php

class IndexController extends pm_Controller_Action
{
    public function init()
    {
        parent::init();

        // Init title for all actions
        $this->view->pageTitle = htmlentities(
            $this->getSetting(Modules_SpamexpertsExtension_Form_Brand::OPTION_BRAND_NAME) ?: "Professional SpamFilter",
            ENT_QUOTES,
            'UTF-8'
        );

        // Init common tabs
        $tabs = [
            [
                'title' => "Domains",
                'action' => 'domains',
            ]
        ];

        if (pm_Session::getClient()->isAdmin()) {
            $tabs[] = [
                'title' => "Settings",
                'action' => 'settings',
            ];
            $tabs[] = [
                'title' => "Branding",
                'action' => 'branding',
            ];
        }

        $tabs[] = [
            'title' => "Support",
            'action' => 'support',
        ];
        
        // Init tabs for all actions
        $this->view->tabs = $tabs;
    }

    public function indexAction()
    {
        $this->_forward('domains');
    }

    public function settingsAction()
    {
        if (!pm_Session::getClient()->isAdmin()) {
            $this->accessDenied();
        }

        // Init form here
        $form = new Modules_SpamexpertsExtension_Form_Settings([]);
        if ($this->getRequest()->isPost()
            && $form->isValid($this->getRequest()->getPost())) {

            if (! Modules_SpamexpertsExtension_Form_Settings::useSettingsFromLicense()) {
                foreach ([
                    $form::OPTION_SPAMPANEL_URL,
                    $form::OPTION_SPAMPANEL_API_HOST,
                    $form::OPTION_SPAMFILTER_MX1,
                    $form::OPTION_SPAMFILTER_MX2,
                    $form::OPTION_SPAMFILTER_MX3,
                    $form::OPTION_SPAMFILTER_MX4,
                         ] as $optionName) {
                    $this->setSetting($optionName, $form->getValue($optionName));
                }

                // API access details need special processing to avoid changing
                // without running the migration procedure
                foreach ([
                             $form::OPTION_SPAMPANEL_API_USER,
                             $form::OPTION_SPAMPANEL_API_PASS,
                         ] as $protectedOptionName) {
                    if (empty($this->getSetting($protectedOptionName))) {
                        $this->setSetting($protectedOptionName, $form->getValue($protectedOptionName));
                    }
                }
            }

            $this->setSetting($form::OPTION_USE_CONFIG_FROM_LICENSE,
                !empty($form->getValue($form::OPTION_USE_CONFIG_FROM_LICENSE)) ? 1 : 0);

            foreach ([
                $form::OPTION_AUTO_ADD_DOMAINS,
                $form::OPTION_AUTO_DEL_DOMAINS,
                $form::OPTION_AUTO_PROVISION_DNS,
                $form::OPTION_AUTO_SET_CONTACT,
                $form::OPTION_EXTRA_DOMAINS_HANDLING,
                $form::OPTION_SKIP_REMOTE_DOMAINS,
                $form::OPTION_LOGOUT_REDIRECT,
                $form::OPTION_AUTO_ADD_DOMAIN_ON_LOGIN,
                $form::OPTION_USE_IP_DESTINATION_ROUTES,
                $form::OPTION_SUPPORT_EMAIL,
            ] as $optionName) {
                $this->setSetting($optionName, $form->getValue($optionName));
            }

            $this->_status->addMessage('info', 'Configuration options were successfully saved.');
            $this->_helper->json(['redirect' => $this->_helper->url('settings')]);
        }

        $this->view->form = $form;
    }

    public function applyconfigAction()
    {
        if (!pm_Session::getClient()->isAdmin()) {
            $this->accessDenied();
        }

        $licenseSettings = \Modules_SpamexpertsExtension_Form_Settings::retrieveFromPleskLicense();
        if (!empty($licenseSettings)) {
            foreach (array_keys($licenseSettings) as $optKey) {
                pm_Settings::set($optKey, $licenseSettings[$optKey]);
            }
        }

        $this->_status->addMessage('info', 'Configuration options have been successfully applied.');
        $this->_redirect('/index/settings', [ 'exit' => true ]);
    }

    public function brandingAction()
    {
        if (!pm_Session::getClient()->isAdmin()) {
            $this->accessDenied();
        }

        $this->checkExtensionConfiguration();

        // Init form here
        $form = new Modules_SpamexpertsExtension_Form_Brand([]);

        if ($this->getRequest()->isPost()
            && $form->isValid($this->getRequest()->getPost())) {

            foreach ([
                $form::OPTION_BRAND_NAME,
                $form::OPTION_LOGO_URL,
            ] as $optionName) {
                $value = $form->getValue($optionName);
                if (null !== $value) {
                    $this->setSetting($optionName, $value);
                }
            }

            $this->_status->addMessage('info', 'Configuration options were successfully saved.');
            $this->_helper->json(['redirect' => $this->_helper->url('branding')]);
        }

        $this->view->form = $form;
    }

    public function domainsAction()
    {
        $this->checkExtensionConfiguration();

        // List object for pm_View_Helper_RenderList
        $this->view->list = $this->getDomainsList();
    }

    public function domainAction()
    {
        $this->checkExtensionConfiguration();

        $contextDomainId = pm_Session::getCurrentDomain()->getId();

        // List object for pm_View_Helper_RenderList
        $this->view->list = $this->getDomainsList(
            !empty($contextDomainId) && is_numeric($contextDomainId) ? [$contextDomainId] : null
        );
    }

    /**
     * @param array $ids
     * @return pm_View_List_Simple
     *
     * @SuppressWarnings(PHPMD.ElseExpression)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    private function getDomainsList(array $ids = [])
    {
        $data = [];
        $dataUrl = 'list-data';

        if (empty($ids)) {
            $domainsManager = new Modules_SpamexpertsExtension_Plesk_Domain_Collection;

            $allDomains = array_merge(
                $domainsManager->getWebspaces(),
                $domainsManager->getSites()
            );
            if (Modules_SpamexpertsExtension_Plesk_Domain_Strategy_Abstract::SECONDARY_DOMAIN_ACTION_SKIP !=
                $this->getSetting(
                    Modules_SpamexpertsExtension_Form_Settings::OPTION_EXTRA_DOMAINS_HANDLING
                )
            ) {
                $allDomains = array_merge(
                    $allDomains,
                    $domainsManager->getAliases()
                );
            }
        } else {
            $allDomains = [];

            foreach ($ids as $domainId) {
                $pleskDomainInstance = new Modules_SpamexpertsExtension_Plesk_Domain(
                    (new pm_Domain($domainId))->getName()
                );
                $allDomains[] = [
                    'id' => $pleskDomainInstance->getId(),
                    'name' => $pleskDomainInstance->getDomain(),
                    'type' => $pleskDomainInstance->getType(),
                ];
            }

            $dataUrl = 'list-context-data';
        }

        $secDomainsStrategy = $this->getSetting(
            Modules_SpamexpertsExtension_Form_Settings::OPTION_EXTRA_DOMAINS_HANDLING
        );

        foreach ($allDomains as $info) {
            $displayLoginLink
                = ($secDomainsStrategy
                ==
                Modules_SpamexpertsExtension_Plesk_Domain_Strategy_Abstract::SECONDARY_DOMAIN_ACTION_PROTECT_AS_DOMAIN)
                || 'Alias' != $info['type'];

            $data[$info['name']] = [
                'domain'     => idn_to_utf8($info['name']),
                'type'       => $info['type'],
                'login-link' => ($displayLoginLink
                    ? '<a target="_blank" href="' . $this->_helper->url('login', 'domain', null, ['domain' => $info['name']])
                        . '" class="s-btn sb-login"><span>Manage in SpamFilter Panel</span></a>'
                    : ''),
            ];
        }

        $options = [
            'defaultSortField' => 'domain',
            'defaultSortDirection' => pm_View_List_Simple::SORT_DIR_UP,
        ];
        $list = new pm_View_List_Simple($this->view, $this->_request, $options);
        $list->setData($data);
        $list->setColumns([
            pm_View_List_Simple::COLUMN_SELECTION,
            'domain' => [
                'title' => 'Link',
                'noEscape' => true,
                'searchable' => true,
            ],
            'type' => [
                'title' => 'Type',
                'searchable' => false,
            ],
            'login-link' => [
                'title' => '',
                'noEscape' => true,
                'sortable' => false,
            ],
        ]);

        $listTools = [
            [
                'title' => 'Check Status',
                'description' => 'Check protection status of selected domains.',
                'class' => 'sb-status-selected',
                'execGroupOperation' => [
                    "url" => $this->_helper->url('status', 'domain'),
                ],
            ],
        ];
        if (pm_Session::getClient()->isAdmin()
            || pm_Session::getClient()->isReseller()) {
            $listTools[] = [
                'title' => 'Protect',
                'description' => 'Add the selected domains to the SpamFilter and enable email filtering.',
                'class' => 'sb-protect-selected',
                'execGroupOperation' => [
                    "url" => $this->_helper->url('protect', 'domain'),
                    "skipConfirmation" => false,
                    "locale" => [
                        "confirmOnGroupOperation" => "You are about to protect the selected domains. Continue?",
                    ],
                    "subtype" => "confirm",
                ],
            ];
            $listTools[] = [
                'title' => 'Unprotect',
                'description' => 'Remove the selected domains from the SpamFilter and disable email filtering.',
                'class' => 'sb-unprotect-selected',
                'execGroupOperation' => [
                    "url" => $this->_helper->url('unprotect', 'domain'),
                    "skipConfirmation" => false,
                    "locale" => [
                        "confirmOnGroupOperation" => "You are about to unprotect the selected domains. Continue?",
                    ],
                    "subtype" => "delete",
                ],
            ];
        }
        $list->setTools($listTools);

        // Take into account listDataAction corresponds to the URL /list-data/
        $list->setDataUrl(['action' => $dataUrl]);

        return $list;
    }

    public function listDataAction()
    {
        $list = $this->getDomainsList();

        // Json data from pm_View_List_Simple
        $this->_helper->json($list->fetchData());
    }

    public function listContextDataAction()
    {
        $contextDomainId = pm_Session::getCurrentDomain()->getId();

        // List object for pm_View_Helper_RenderList
        $list = $this->getDomainsList(
            !empty($contextDomainId) && is_numeric($contextDomainId) ? [$contextDomainId] : null
        );

        // Json data from pm_View_List_Simple
        $this->_helper->json($list->fetchData());
    }

    public function supportAction()
    {
        $this->checkExtensionConfiguration();

        $supportEmail = $this->getSetting(\Modules_SpamexpertsExtension_Form_Settings::OPTION_SUPPORT_EMAIL);
        if (!empty($supportEmail)) {
            $supportForm = new Modules_SpamexpertsExtension_Form_SupportRequest([]);

            if ($this->getRequest()->isPost()) {
                if ($supportForm->isValid($this->getRequest()->getPost())) {
                    $pleskVersion = pm_ProductInfo::getVersion() . " (" . pm_ProductInfo::getPlatform() . ")";
                    $phpVersion = PHP_VERSION;
                    /** @var stdClass $ext */
                    $ext = pm_Context::getModuleInfo();
                    $extensionVersion = "v{$ext->version}-{$ext->release}";
                    $message = <<< MESSAGE
Hello there!

A new support request from Plesk Extension was submitted. The details are:

Plesk: {$pleskVersion}
PHP version: {$phpVersion}
Extension version: {$extensionVersion}

Reply-To: {$supportForm->getValue($supportForm::OPTION_REPLY_TO)}
Subject: {$supportForm->getValue($supportForm::OPTION_TITLE)}
Message:

{$supportForm->getValue($supportForm::OPTION_MESSAGE)}
MESSAGE;

                    $isSent = mail($supportEmail, 'Plesk Extension: New support request', $message,
                        "From: {$supportForm->getValue($supportForm::OPTION_REPLY_TO)}\r\n" .
                        "Reply-To: {$supportForm->getValue($supportForm::OPTION_REPLY_TO)}\r\n");
                    if ($isSent) {
                        $this->_status->addMessage('info', 'Your message has been sent.');
                        $this->_helper->json(['redirect' => $this->_helper->url('support')]);
                    }
                } else {
                    $this->_status->addMessage('error', 'Please enter correct values into the form.');
                    $this->_helper->json(['redirect' => $this->_helper->url('support')]);
                }
            }

            $this->view->supportForm = $supportForm;
        }
    }

    protected function accessDenied()
    {
        throw new pm_Exception('Access denied');
    }

    /**
     * @throws pm_Exception
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    protected function checkExtensionConfiguration()
    {
        if (Modules_SpamexpertsExtension_Form_Settings::areEmpty()) {
            if (pm_Session::getClient()->isAdmin()) {
                $this->_status->addMessage(
                    'error',
                    'Extension is not configured yet. Please set up configuration options.'
                );
                $this->_redirect('/index/settings', [ 'exit' => true ]);

                return;
            }

            throw new pm_Exception(
                'Extension is not configured yet. Please ask your system administrator to fix that.'
            );
        }
    }

    /**
     * Extracts values from Plesk key-value storage
     *
     * @param string $id
     *
     * @return string
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    protected function getSetting($id)
    {
        return pm_Settings::get($id);
    }

    /**
     * Pushes values to Plesk key-value storage
     *
     * @param string $id
     * @param string $value
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    protected function setSetting($id, $value)
    {
        pm_Settings::set($id, $value);
    }

}
