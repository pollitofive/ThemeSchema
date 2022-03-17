<?php
/**
 * ThemeSchema Plugin
 *
 * @author Damián Ladiani <http://www.damianladiani.com/>
 * @copyright 2019 Damián Ladiani <http://www.damianladiani.com/>
 * @license Propietary
 * @version 1.0.0
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 */
class ThemeSchema extends PluginBase {

    use SecurityTrait;

    protected $storage = 'DbStorage';
    static protected $description = 'ThemeSchema';
    static protected $name = 'ThemeSchema';
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

    public function init()
    {
        $this->subscribe('beforeSurveySettings');
        $this->subscribe('newSurveySettings');
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

         $this->getPluginLink('getJsonData', $surveyId);

        $newSettings = array(
            'activate' => array(
                'type'=>'boolean',
                'label'=>'Activate',
                'help'=>'',
                'current' => $this->get('activate', 'Survey', $surveyId, $this->get('activate'))
            ),
        );

        // Set all settings
        $event->set("surveysettings.{$this->id}", array(
            'name' => get_class($this),
            'settings' => $newSettings,
        ));
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

        if (is_array($responses))
        {
            $responses = '{"responses":[]}';
        }
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
