<?php
/**
 * PowerBIConnector Pugin
 *
 * @author Gabriel Jenik <http://www.encuesta.biz/>
 * @copyright 2019 Gabriel Jenik <http://www.encuesta.biz/>
 * @license Propietary
 * @version 1.0.8
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 */
class PowerBIConnector extends PluginBase {
    protected $storage = 'DbStorage';
    static protected $description = 'PowerBIConnector';
    static protected $name = 'PowerBIConnector';
    static protected $loadTranslations = TRUE;
    static protected $useActivateSurveyLevel = TRUE;

    const DEFAULT_ANSWER_FORMAT = 'long';

    const NUMERIC_TYPES = [
        'K',    // Multiple numerical
        'N',    // Numerical
        '5',    // 5 point choice
        'B',    // Array (10 point choice)
        'A',    // Array (5 point choice)
        ':'     // Array (Numbers)
    ];

    // Global Settings
    protected $settings = [
        'maxFailedAttempts' => [
            'type' => 'int',
            'label' => 'Max Number of Failed Attempts',
            'default' => 5,
            //'help' => "",
        ],
        'ipWhitelist' => [
            'type' => 'text',
            'label' => 'Only allow connection from these IPs',
            'default' => '',
            'help' => 'IPs can be entered as IPs or using wildcards. Example:<br>192.168.0.1<br>192.168.*.*',
        ],
        'memoryLimit' => [
            'type' => 'string',
            'label' => 'Memory limit',
            'default' => null,
            'help' => "Leave empty to use the default memory limit (%CURRENT_MEMORY_LIMIT%)",
        ],
        'maxExecTime' => [
            'type' => 'int',
            'label' => 'Max Execution Time',
            'default' => null,
            'help' => "Leave empty to use the default max execution time (%CURRENT_MAX_EXEC_TIME%)",
        ],
    ];

    public function init()
    {
        $this->subscribe('beforeSurveySettings');
        $this->subscribe('newSurveySettings');
        $this->subscribe('newQuestionAttributes');
        $this->subscribe('beforeControllerAction');

        //Can call plugin
        $this->subscribe('newUnsecureRequest');
        $this->subscribe('newDirectRequest', 'newUnsecureRequest');
    }

    /**
     * Survey Settings
     */
    public function beforeSurveySettings()
    {


        $event = $this->event;
        $surveyId = $event->get("survey");
        $oSurvey = Survey::model()->findByPk($surveyId);

        /*$getJsonDataPath =  $this->api->createUrl('plugins/unsecure', array(
            'plugin' => $this->getName(),
            'function' => 'getJsonData',
            'surveyId' => $surveyId,
            'token' => $this->get('token', 'Survey', $surveyId),
        ));*/
        $pluginName = get_class();
        $token = $this->get('token', 'Survey', $surveyId);
        $lang = $this->get('language', 'Survey', $surveyId, $oSurvey->language);
        $answers = $this->get('answerFormat', 'Survey', $surveyId, self::DEFAULT_ANSWER_FORMAT);
        $getJsonDataPath =  $this->api->createUrl("survey/index/{$pluginName}/{$surveyId}/t/{$token}/lang/{$lang}/answers/{$answers}",[]);
        $getJsonDataPathBasic =  $this->api->createUrl("survey/index/{$pluginName}/{$surveyId}/t/{$token}",[]);

        $this->getPluginLink('getJsonData', $surveyId);

        $baseURL = $this->getPluginBaseUrl() . "/";
        
        $newSettings = array(
            'activate' => array(
                'type'=>'boolean',
                'label'=>'Activate',
                'help'=>'',
                'current' => $this->get('activate', 'Survey', $surveyId, $this->get('activate'))
            ),
            'debug' => array(
                'type'=>'boolean',
                'label'=>'Debug',
                'help'=>'Show debugging information while running',
                'current' => $this->get('debug', 'Survey', $surveyId, $this->get('debug'))
            ),
            'language' => array(
                'type' => 'string',
                'label' => 'Export language:',
                'current' => $this->get('language', 'Survey', $surveyId, $oSurvey->language),
                'help' => 'Language used when exporting the responses. Can be overriden using the \'lang\' url parameter.',
            ),
            'answerFormat' => array(
                'type' => 'select',
                'label' => 'Export responses as:',
                'options'=>array(
                    'short' => 'Answer codes',
                    'long' => 'Full answers',
                  ),
                'current' => $this->get('answerFormat', 'Survey', $surveyId, self::DEFAULT_ANSWER_FORMAT),
                'help' => 'Export answer codes (\'short\') or full answers (\'long\'). Can be overriden using the \'answers\' url parameter.',
            ),
            'token' => array(
                'type' => 'string',
                'label' => 'Security Token:',
                'current' => $this->get('token', 'Survey', $surveyId),
                'help' => 'Security token to be used for PowerBI to connect to LimeSurvey.',
            ),
            'maxFailedAttempts' => array(
                'type' => 'int',
                'label' => 'Max Number of Failed Attempts:',
                'current' => $this->get('maxFailedAttempts', 'Survey', $surveyId, $this->settings['maxFailedAttempts']['default']),
                //'help' => '',
            ),
            'failedAttempts' => array(
                'type' => 'int',
                'label' => 'Failed Attempts:',
                'htmlOptions' => array(
                    'readonly' => true,
                ),
                'current' => $this->get('failedAttempts', 'Survey', $surveyId, 0),
                //'help' => '',
            ),
            'ipWhitelist' => array(
                'type' => 'text',
                'label' => 'Only allow connection from these IPs:',
                'current' => $this->get('ipWhitelist', 'Survey', $surveyId),
                'help' => 'IPs can be entered as IPs or using wildcards. Example:<br>192.168.0.1<br>192.168.*.*',
            ),
            'connectionURL' => array(
                'type' => 'info',
                'content' => '<label class="default control-label col-sm-6" for="plugin_PowerBIConnector_url">Connection URL:</label>
                <div class="default col-sm-6 controls"><input size="50" class="form-control" type="text" value="' . $getJsonDataPath . '" id="plugin_PowerBIConnector_url" readonly>
                <div class="help-block">See the usage details below</div></div>',            
            ),
            'usageInfo' => array(
                'type' => 'info',
                'content' => '<hr/>
                <h2>Usage instructions</h2>
                <div>Open Power BI > Get Data > JSON > [Connection URL]</div><br/>
                <div class="alert alert-warning"><strong>Warning:</strong>&nbsp;The Connection URL shown above <strong>is based on the last saved settings</strong>. If you changed the settings, please save and check the URL again.</div>
                <br/>
                <p><h3>Step 1:</h3><img src="' . $baseURL . 'images/Step1.jpg" style="max-width:100%; height:auto;"></p><br/>
                <p><h3>Step 2:</h3><img src="' . $baseURL . 'images/Step2.jpg" style="max-width:100%; height:auto;"></p><br/>
                <p><h3>Step 3:</h3>In the "File name" field, paste your Connection URL: <strong>' . $getJsonDataPath . '</strong><img src="' . $baseURL . 'images/Step3.jpg" style="max-width:100%; height:auto;"></p>
                <br/>
                <p><h3>Note:</h3>You can omit the language and answer format from the URL to use the default values. Example: ' . $getJsonDataPathBasic . '</p>',            
            ),
        );

        // Set all settings
        $event->set("surveysettings.{$this->id}", array(
            'name' => get_class($this),
            'settings' => $newSettings,
        ));
    }

    /**
     * Override getPluginSettings
     */
    public function getPluginSettings($getValues = true)
    {
        $maxExecTime = ini_get('max_execution_time');
        $memoryLimit = ini_get('memory_limit');

        $this->settings['memoryLimit']['help'] = str_replace("%CURRENT_MEMORY_LIMIT%", $memoryLimit, $this->settings['memoryLimit']['help']);
        $this->settings['maxExecTime']['help'] = str_replace("%CURRENT_MAX_EXEC_TIME%", $maxExecTime, $this->settings['maxExecTime']['help']);

        return parent::getPluginSettings($getValues);
    }

    /**
     * Subscription to newUnsecureRequest Request event
     */
    public function newUnsecureRequest()
    {
        $oEvent = $this->event;

        if ($oEvent->get('target') != $this->getName()) return;

        // Check Active
        $request = Yii::app()->request;
        $surveyId = $request->getParam('surveyId', 0);
        if (!$this->get('activate', 'Survey', $surveyId))
        {
            return;
        }

        $token = $request->getParam('token');
        /**
         * Check the security token.
         * 
         * The security token specified in the request should match
         * the one in the survey's plugin settings.
         * If it's missing or doesn't match, the plugin fails with
         * a 404 code.
         * 
         */
        if ($token!=$this->get('token', 'Survey', $surveyId)) {
            http_response_code(404);
            die();
        }

        /**
         * Initializa output
         */
        $out = $oEvent->getContent($this);
        // $out->addContent("<p>Processing " . $oEvent->get('function') . "</p>");

        /**
         * Process request
         */

        $content = "";

        // If this is a showChart request
        if ($oEvent->get('function') == 'getJsonData')
        {
            $lang = $request->getParam('lang');
            if (empty($lang)) $lang = $this->get('language', 'Survey', $surveyId);
            $answers = $request->getParam('answers');
            if (empty($answers)) $answers = $this->get('answerFormat', 'Survey', $surveyId, self::DEFAULT_ANSWER_FORMAT);

            $content = $this->getJsonData($surveyId, $lang, $answers);
        }

        /**
         * Finish output
         */

        //$out->addContent($content);
        $oEvent->setContent($this, $content);
        return;
    }

  public function getJsonData($surveyid, $lang, $answerFormat)
  {
    $this->applyCustomPHPLimits();

    // Sanitize $answerFormat
    if (!in_array($answerFormat, ['short', 'long'])) $answerFormat = self::DEFAULT_ANSWER_FORMAT;

    $oSurvey = Survey::model()->findByPk($surveyid);
    if (!in_array($lang, $oSurvey->allLanguages)) $lang = $oSurvey->language;

    /**
     * Fetch Responses
     */
    // Get JSON Responses
    \Yii::app()->loadHelper('admin/exportresults');
    $oFormattingOptions = new \FormattingOptions();
    Yii::log("Getting responses", 'DEBUG','application.plugins.PowerBIConnector');
    $responses = $this->fetchResponses($surveyid, 'json', $lang, 'complete', 'code', $answerFormat, NULL, NULL, NULL, $oFormattingOptions);

    // If it's an array, then an error seems to have appeared
    if (is_array($responses))
    {
      //echo "/*\n";
      //var_dump($responses);
      //echo "\n*/";
      $responses = '{"responses":[]}';
    }
    //exit(print_r($responses));
    $a_responses = json_decode($responses);

    $types = $this->getTypes($surveyid);

    // Transform Responses JSON
    Yii::log("Transforming responses", 'DEBUG','application.plugins.PowerBIConnector');
    $reportDef_Data=array();
    foreach ($a_responses->responses as $response) {
        $responseArray = get_object_vars($response);
        // JsonWriter changed on version 4.3.19, so we need to check the structure
        if (count($responseArray) == 1) {
            $to_output_object = reset($responseArray);
        } else {
            $to_output_object = $response;
        }
        foreach ($to_output_object as $key => $value) {
            if (is_null($value)) continue;

            $type = !empty($types[$key]) ? $types[$key] : 'text';
            switch ($type) {
                case 'number': $to_output_object->$key = strlen($value) == 0 ? $value : (double) $value; break;
                case 'date': $to_output_object->$key = str_replace("-", "/", $value);
                default: $to_output_object->$key = (string) $value; break;
            }
        }
        $reportDef_Data[]=$to_output_object;
    }

    /**
     * Output
     */
    Yii::log("Sending output", 'DEBUG','application.plugins.PowerBIConnector');
    header("Content-Disposition: inline; filename=survey_{$surveyid}.json");
    header("Content-type: application/json");
    $to_output_json = json_encode($reportDef_Data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
    echo $to_output_json;
    die(); // Don't output full body. Just this json.
  }
  
    /**
     * Handle Survey Settings Saving
     */
    public function newSurveySettings()
    {
        $event = $this->event;

        foreach ($event->get('settings') as $name => $value)
        {
            // Avoid updating the 'failedAttempts' setting
            if ($name!='failedAttempts') {
                $this->set($name, $value, 'Survey', $event->get('survey'));
            }
        }
    }

    public function getPluginLink($method, $surveyId = NULL, $sa = "ajax", $extraParams = NULL)
    {
        if (empty($extraParams)) $extraParams = [];
        $params = $extraParams;

        $params['plugin'] = $this->getName();
        $params['sa'] = $sa;
        $params['method'] = $method;
        if (!empty($surveyId)) $params['surveyId'] = $surveyId;

        return $this->api->createUrl('admin/pluginhelper', $params);
    }

    protected function getPluginDir($class = NULL)
    {
        if ($this->LSVersionCompare("4")) {
            return $this->getPluginDirLS4($class);
        }

        if (empty($class)) $class = get_class($this);

        $basePath = __DIR__
                    . DIRECTORY_SEPARATOR .'..'
                    . DIRECTORY_SEPARATOR . '..'
                    . DIRECTORY_SEPARATOR . $class;

        return $basePath;
    }

    protected function getPluginDirLS4($class = NULL)
    {
        if (empty($class)) {
            $object = new \ReflectionObject($this);
        } else {
            $object = new \ReflectionClass($class);
        }

        $filename = $object->getFileName();
        $basePath = dirname($filename);

        return $basePath;
    }

    protected function getPluginBaseUrl()
    {
        $pluginDir = $this->getPluginDir();
        $pluginDir = str_replace(FCPATH, "", $pluginDir);
        $url = \Yii::app()->getConfig('publicurl') . $pluginDir;
        return $url;
    }

    /**
     * Get responses in multiple formats.
     * Taken from RemoteControl::export_responses()
     *
     * @param int $iSurveyID Id of the Survey
     * @param string $sDocumentType pdf,csv,xls,doc,json
     * @param string $sLanguageCode The language to be used
     * @param string $sCompletionStatus Optional 'complete','incomplete' or 'all' - defaults to 'all'
     * @param string $sHeadingType 'code','full' or 'abbreviated' Optional defaults to 'code'
     * @param string $sResponseType 'short' or 'long' Optional defaults to 'short'
     * @param integer $iFromResponseID Optional
     * @param integer $iToResponseID Optional
     * @param array $aFields Optional Selected fields
     * @param oFormattingOptions Added on PluginBaseLS3xx. Default oFormattingOptions to use.
     * @return array|string On success: Requested file as base 64-encoded string. On failure array with error information
     */
    public function fetchResponses($iSurveyID, $sDocumentType = 'json', $sLanguageCode='en', $sCompletionStatus='all', $sHeadingType='code', $sResponseType='short', $iFromResponseID=null, $iToResponseID=null, $aFields=null, $oFormattingOptions=NULL)
    {

        /**
         * Initialization
         */
        $S = \Survey::model()->findByPk($iSurveyID);
        if (empty($sLanguageCode)) $sLanguageCode = $S->language;
        $fullMap = $this->CreateFieldMap($S,'full',true,false,$sLanguageCode);

        /**
         * Original Code
         */
        \Yii::app()->loadHelper('admin/exportresults');
        if (!tableExists('{{survey_' . $iSurveyID . '}}')) return array('status' => 'No Data, survey table does not exist.');
        if(!($maxId = \SurveyDynamic::model($iSurveyID)->getMaxId())) return array('status' => 'No Data, could not get max id.');
        if(!empty($sLanguageCode) && !in_array($sLanguageCode, \Survey::model()->findByPk($iSurveyID)->getAllLanguages()) ) return array('status' => 'Language code not found for this survey.');

        if (is_null($aFields)) $aFields=array_keys($fullMap);
        if($sDocumentType=='xls'){
            // Cut down to the first 255 fields
          $aFields=array_slice($aFields,0,255);
        }

        // Extra Initialization, not on original export_responses method
        \Yii::app()->loadHelper('export');
        \Yii::import('application.helpers.viewHelper', true);

        /**
         * Patch for not having a failure on SurveyDao.php:66
         * Set an action property
         */
        \Yii::app()->controller->__set('action', \Yii::app()->controller->getAction());

        /**
         * Setup Formatting Options for Exporter
         */
        if (empty($oFormattingOptions)) $oFormattingOptions=new \FormattingOptions();

        if($iFromResponseID !=null)
          $oFormattingOptions->responseMinRecord=$iFromResponseID;
        elseif($oFormattingOptions->responseMinRecord == NULL)
          $oFormattingOptions->responseMinRecord=1;

        if($iToResponseID !=null)
          $oFormattingOptions->responseMaxRecord=$iToResponseID;
        elseif($oFormattingOptions->responseMaxRecord == NULL)
          $oFormattingOptions->responseMaxRecord = $maxId;

        if ($oFormattingOptions->selectedColumns == NULL) $oFormattingOptions->selectedColumns=$aFields;
        if ($oFormattingOptions->responseCompletionState == NULL) $oFormattingOptions->responseCompletionState=$sCompletionStatus;
        if ($oFormattingOptions->headingFormat == NULL) $oFormattingOptions->headingFormat=$sHeadingType;
        if ($oFormattingOptions->answerFormat == NULL) $oFormattingOptions->answerFormat=$sResponseType;
        if ($oFormattingOptions->output == NULL) $oFormattingOptions->output='display';


        /**
         * Call the writer and capture the contents
         */
        $oExport=new \ExportSurveyResultsService();
        ob_start();
        if($this->LSVersionCompare("3.17.1","<="))
            $oExport->exportSurvey($iSurveyID,$sLanguageCode, $sDocumentType,$oFormattingOptions, '');
        else
            $oExport->exportResponses($iSurveyID,$sLanguageCode, $sDocumentType,$oFormattingOptions, '');
        $results = ob_get_contents();
        ob_end_clean();

        return $results;
    }

    /**
     * Create Field Map (version adapted)
     */
    public function CreateFieldMap($S, $style='short', $force_refresh=false, $questionid=false, $sLanguage='', &$aDuplicateQIDs=array())
    {
        // Get full map
        if($this->LSVersionCompare("3",">="))
        {
            return createFieldMap($S, $style, $force_refresh, $questionid, $sLanguage, $aDuplicateQIDs);
        }
        else
        {
            return createFieldMap($S->sid, $style, $force_refresh, $questionid, $sLanguage, $aDuplicateQIDs);
        }
    }

    /**
     * Get Current LS Version
     */
    public function LSVersion()
    {
        return \Yii::app()->getConfig('versionnumber');
    }
    /**
     * Compare Current LS Version with requested
     */
    public function LSVersionCompare($version, $compare = ">=")
    {
        return version_compare($this->LSVersion(), $version, $compare);
    }

    public function newQuestionAttributes()
    {
        $event = $this->getEvent();
        $questionAttributes = [
            'powerbiDataType' => [
                'types'     => '15ABCDEFGHIKLMNOPQRSTUXY!:;|',
                'category'  => 'PowerBI Connector',
                //'sortorder' => 1,
                'inputtype' => 'singleselect',
                'default'   => 'default',
                'help'      => 'Data type to use when exporting to JSON.',
                'caption'   => 'Field data type',
                'expression'=>[],
                'options'   => array(
                    'default' => gT('Default'),
                    'text' => gT('Text'),
                    'number' => gT('Number'),
                    'date' => gT('Date'),
                ),
            ],
        ];
        $event->append('questionAttributes', $questionAttributes);
    }

    protected function getTypes($surveyId)
    {
        $survey = Survey::model()->findByPk($surveyId);

        /**
         * Fetch Fieldmap
         */

        // Get full map
        $fullMap = $this->CreateFieldMap($survey, 'full', true, false, $survey->language);

        \Yii::import('application.helpers.viewHelper', true);

        // Compose out map (first row of reportDef_Data)
        $outMap = [];
        foreach ($fullMap as $sgqa => $Q) {

            // Set type to 'default' for standard fields
            if (empty($Q['title'])) {
                switch ($Q['fieldname']) {
                    case 'id':
                    case 'lastpage':
                        $type = 'number';
                        break;
                    case 'submitdate':
                    case 'startdate':
                    case 'datestamp':
                        $type = 'date';
                        break;
                    default:
                        $type = 'text';
                }
                $outMap[$Q['fieldname']] = $type;
                continue;
            }

            // Get Data Type attribute
            $attributes = QuestionAttribute::model()->getQuestionAttributes($Q['qid']);

            $type = !empty($attributes['powerbiDataType']) ? $attributes['powerbiDataType'] : 'default';

            // If type was not set above, try do determine it based on question type
            if ($type == 'default') {
                // If question type is numeric, set as number
                if (in_array($Q['type'], self::NUMERIC_TYPES))
                {
                    $type = 'number';
                } 

                // If question type is date/time, set as date
                elseif ($Q['type'] == 'D')
                {
                    $type = 'date';
                }

                // If question type is equation, it depends on the numbers_only attribute
                elseif ($Q['type'] == '*')
                {
                    $type = empty($attributes['numbers_only']) ? 'text' : 'number';
                }

                // Otherwise, set as text
                else {
                    $type = 'text';
                }
            }

            $code = viewHelper::getFieldCode($Q, array('separator'=>array('[', ']'), 'LEMcompat'=>null));

            // Push the question's type in the collection
            $outMap[$code] = ($type != '') ? $type : 'text';
        }

        return $outMap;
    }

    public function beforeControllerAction()
    {
        $controller = $this->event->get('controller');
        $action = $this->event->get('action');

        if (!($controller=='survey' && $action=='index')) return;

        // Check target
        $mainParam = get_class();
        if (!array_key_exists($mainParam, $_GET) && !array_key_exists($mainParam, $_POST)) return;

        // Check Active
        $request = Yii::app()->request;
        $surveyId = $request->getParam($mainParam, null);
        if (!$this->get('activate', 'Survey', $surveyId))
        {
            $this->event->set('run', false);
            return;
        }

        // Check the IP is whitelisted
        if (!$this->isIpWhitelisted($surveyId)) {
            http_response_code(404);
            die();
        }

        $token = $request->getParam('t');
        /**
         * Check the security token.
         * 
         * The security token specified in the request should match
         * the one in the survey's plugin settings.
         * If it's missing or doesn't match, the plugin fails with
         * a 404 code.
         * 
         */
        if (!$this->validateToken($surveyId, $token)) {
            http_response_code(404);
            die();
        }

        $lang = $request->getParam('lang');
        if (empty($lang)) $lang = $this->get('language', 'Survey', $surveyId);
        $answers = $request->getParam('answers');
        if (empty($answers)) $answers = $this->get('answerFormat', 'Survey', $surveyId, self::DEFAULT_ANSWER_FORMAT);

        $content = $this->getJsonData($surveyId, $lang, $answers);
        echo $content;
        $this->event->set('run', false);
    }

    /**
     * Checks that the token is valid.
     * It takes into account the failed attempts.
     */
    private function validateToken($surveyId, $token)
    {
        // Before validating the token we need to check the number of failed attempts
        if (!$this->validateFailedAttempts($surveyId)) {
            return false;
        }

        if ($token != $this->get('token', 'Survey', $surveyId)) {
            $this->increaseFailedAttempts($surveyId);
            return false;
        }

        $this->resetFailedAttempts($surveyId);

        return true;
    }

    private function validateFailedAttempts($surveyId)
    {
        $failedAttempts = $this->get('failedAttempts', 'Survey', $surveyId, 0);
        $maxFailedAttempts = $this->getSetting('maxFailedAttempts', $surveyId);
        if ($failedAttempts >= $maxFailedAttempts) {
            return false;
        }

        return true;
    }

    private function increaseFailedAttempts($surveyId)
    {
        $failedAttempts = $this->get('failedAttempts', 'Survey', $surveyId, 0);
        //$maxFailedAttempts = $this->getSetting('maxFailedAttempts', $surveyId);

        // Update the setting
        $this->set('failedAttempts', ++$failedAttempts, 'Survey', $surveyId);
    }

    private function resetFailedAttempts($surveyId)
    {
        $this->set('failedAttempts', 0, 'Survey', $surveyId);
    }

    private function isIpWhitelisted($surveyId)
    {
        $ip = substr(getIPAddress(), 0, 40);
        $whiteList = $this->getIpWhiteList($surveyId);

        foreach ($whiteList as $whiteListEntry) {
            if (!empty($whiteListEntry) && preg_match('/' . str_replace('*', '\d+', $whiteListEntry) . '/', $ip, $m)) {
                return true;
            }
        }
        return false;
    }

    private function getIpWhiteList($surveyId)
    {
        $whiteList = [];
        $rawList = $this->getSetting('ipWhitelist', $surveyId, '');
        if (!empty($rawList)) {
            $whiteList = preg_split("/\R|,|;/", $rawList);
        }
        return $whiteList;
    }

    private function getSetting($setting, $surveyId = null, $default = null)
    {
        if (!empty($surveyId)) $value = $this->get($setting, "Survey", $surveyId);
        if (is_null($value) || strlen($value) == 0 || $value == 'default') {

            $value = $this->get($setting, null, null, isset($this->settings[$setting]['default']) ? $this->settings[$setting]['default'] : null);
        }
        if (is_null($value) || strlen($value) == 0) {
            $value = $default;
        }
        return $value;
    }

    private function applyCustomPHPLimits()
    {
        $maxExecTime = $this->get('maxExecTime');
        if (!empty($maxExecTime)) {
            Yii::log("Setting max_execution_time to $maxExecTime", 'DEBUG','application.plugins.PowerBIConnector');
            ini_set('max_execution_time', (int) $maxExecTime);
        }

        $memoryLimit = $this->get('memoryLimit');
        if (!empty($memoryLimit)) {
            Yii::log("Setting memory_limit to $memoryLimit", 'DEBUG','application.plugins.PowerBIConnector');
            ini_set('memory_limit', $memoryLimit);
        }
    }
}
