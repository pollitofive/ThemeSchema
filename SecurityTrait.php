<?php


trait SecurityTrait
{
    /**
     * Checks that the token is valid.
     * It takes into account the failed attempts.
     */
    protected function validateToken($surveyId, $token)
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

    protected function validateFailedAttempts($surveyId)
    {
        $failedAttempts = $this->get('failedAttempts', 'Survey', $surveyId, 0);
        $maxFailedAttempts = $this->getSetting('maxFailedAttempts', $surveyId);
        if ($failedAttempts >= $maxFailedAttempts) {
            return false;
        }

        return true;
    }

    protected function increaseFailedAttempts($surveyId)
    {
        $failedAttempts = $this->get('failedAttempts', 'Survey', $surveyId, 0);
        //$maxFailedAttempts = $this->getSetting('maxFailedAttempts', $surveyId);

        // Update the setting
        $this->set('failedAttempts', ++$failedAttempts, 'Survey', $surveyId);
    }

    protected function resetFailedAttempts($surveyId)
    {
        $this->set('failedAttempts', 0, 'Survey', $surveyId);
    }

    protected function isIpWhitelisted($surveyId)
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

    protected function getIpWhiteList($surveyId)
    {
        $whiteList = [];
        $rawList = $this->getSetting('ipWhitelist', $surveyId, '');
        if (!empty($rawList)) {
            $whiteList = preg_split("/\R|,|;/", $rawList);
        }
        return $whiteList;
    }
}
