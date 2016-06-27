<?php

class Modules_SpamexpertsExtension_Plesk_Domain
{
    use Modules_SpamexpertsExtension_Plesk_ApiClientTrait;

    const TYPE_SITE = 'site';
    const TYPE_WEBSPACE = 'webspace';
    const TYPE_ALIAS = 'site-alias';
    const TYPE_SUBDOMAIN = 'subdomain';

    /**
     * Domain ASCII name
     *
     * @var string
     */
    protected $domain;

    /**
     * Domain type, possible types are:
     * webspace, site, site-alias, subdomain
     * 
     * @var string
     */
    protected $type;

    /**
     * Domain ID
     * 
     * @var int
     */
    protected $id;

    /**
     * Class constructor
     *
     * @param string $domain
     * @param string $type
     * @param string $id
     *
     * @return Modules_SpamexpertsExtension_Plesk_Domain
     */
    public function __construct($domain, $type = null, $id = null)
    {
        $this->domain = $domain;
        $this->type = $type;
        $this->id = $id;
    }

    /**
     * Domain ID getter
     *
     * @return int
     */
    public function getId()
    {
        if (null === $this->id) {
            if (null !== $this->type) {
                $this->id = $this->getDomainId($this->domain, $this->type);
            } else {
                foreach ([self::TYPE_SITE, self::TYPE_WEBSPACE, self::TYPE_ALIAS, self::TYPE_SUBDOMAIN] as $type) {
                    $this->id = $this->getDomainId($this->domain, $type);
                    if (null !== $this->id) {
                        $this->type = $type;

                        break;
                    }
                }
            }
        }

        return $this->id;
    }

    /**
     * Domain type getter
     *
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Domain name getter
     *
     * @return string
     */
    public function getDomain()
    {
        return $this->domain;
    }

    /**
     * @param string $domainName
     * @param string $type
     *
     * @return int
     */
    protected function getDomainId($domainName, $type)
    {
        $id = null;

        if (!in_array($type, [self::TYPE_SITE, self::TYPE_WEBSPACE, self::TYPE_ALIAS, self::TYPE_SUBDOMAIN])) {
            return $id;
        }

        switch ($type) {
            case self::TYPE_SITE:
            case self::TYPE_WEBSPACE:
                $request = <<<APICALL
<$type>
  <get>
    <filter>
      <name>{$domainName}</name>
    </filter>
    <dataset>
      <gen_info></gen_info>
    </dataset>
  </get>
</$type>
APICALL;
                break;

            case self::TYPE_ALIAS:
            case self::TYPE_SUBDOMAIN:
                $request = <<<APICALL
<$type>
  <get>
    <filter>
      <name>{$domainName}</name>
    </filter>
  </get>
</$type>
APICALL;
                break;

            default:
                return $id;
        }


        $response = $this->xmlapi($request);

        if ('ok' == $response->$type->get->result->status) {
            $id = (int) $response->$type->get->result->id;
        }

        return $id;
    }

    /**
     * Webspaces getter
     *
     * @return array
     */
    final static public function getWebspaces()
    {
        $list = [];

        $request = <<<APICALL
<webspace>
  <get>
    <filter></filter>
    <dataset>
      <gen_info></gen_info>
    </dataset>
  </get>
</webspace>
APICALL;
        $response = pm_ApiRpc::getService()->call($request);

        foreach ($response->webspace->get->result as $domainInfo) {
            if ('ok' == $domainInfo->status && !empty($domainInfo->data)) {
                $asciiDomain = (string) $domainInfo->data->gen_info->{"ascii-name"};
                $list[] = [
                    'name' => $asciiDomain,
                    'type' => 'Webspace',
                ];
            }
        }

        return $list;
    }

    /**
     * Sites getter
     *
     * @return array
     */
    final static public function getSites()
    {
        $list = [];

        $request = <<<APICALL
<site>
  <get>
    <filter></filter>
    <dataset>
      <gen_info></gen_info>
    </dataset>
  </get>
</site>
APICALL;
        $response = pm_ApiRpc::getService()->call($request);

        foreach ($response->site->get->result as $domainInfo) {
            if ('ok' == $domainInfo->status && !empty($domainInfo->data)) {
                $asciiDomain = (string) $domainInfo->data->gen_info->{"ascii-name"};
                $list[] = [
                    'name' => $asciiDomain,
                    'type' => 'Site',
                ];
            }
        }

        return $list;
    }


    /**
     * Aliases getter
     *
     * @param array $filter
     * 
     * @return array
     */
    final static public function getAliases(array $filter = null)
    {
        $list = [];

        $xmlFilter = '';
        if (null !== $filter) {
            foreach ($filter as $fName => $fValue) {
                $xmlFilter .= "<$fName>$fValue</$fName>";;
            }
        }

        $request = <<<APICALL
<site-alias>
  <get>
    <filter>$xmlFilter</filter>
  </get>
</site-alias>
APICALL;
        $response = pm_ApiRpc::getService()->call($request);

        foreach ($response->{"site-alias"}->get->result as $domainInfo) {
            if ('ok' == $domainInfo->status && !empty($domainInfo->info)) {
                $asciiDomain = (string) $domainInfo->info->{"ascii-name"};
                $list[] = [
                    'name' => $asciiDomain,
                    'type' => 'Alias',
                ];
            }
        }

        return $list;
    }

}
