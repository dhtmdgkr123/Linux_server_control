<?php

if (!class_exists('ExecCommand')) {
    defined('BASEPATH') or exit('No direct script access allowed');
    class ExecCommand extends CI_Model
    {
        private $processType = null;
        private $idxErr = 'indexError';
        private $userData = null;
        private $connInfo = null;
        private $processCode = null;
        private $queueHash = null;


        public function __construct()
        {
            parent::__construct();
            $load = $this->load;
            $load->library('session');
            $load->helper('idFilter');
            $load->library('Queue');

            $this->queueHash = $this->session->queueHash;
            $this->processCode = (object) [
                'failConnect'   => -1,
                'ok'            => 1,
                'failGetStream' => -2,
            ];
            $this->userData = (object) [
                'serverAddress' => $this->session->serverAddress,
                'serverPort'    => $this->session->serverPort,
                'userId'        => $this->session->userId,
                'userPassword'  => $this->session->userPassword,
            ];
        }

        private function renderPathTemplate(string $path) : string
        {
            $base = $this->userData->userId.'@'.$this->userData->serverAddress.' : '.$path;
            if (isRoot($this->userData->userId)) {
                $base .= ' #';
            } else {
                $base .= ' $';
            }
            return $base;
        }

        public function printWorkingDir($cachePwd): array
        {
            $retArr = [
                'status' => false,
                'code'   => $this->processCode->failConnect,
                'page'   => 'getPwd',
            ];

            if ($cachePwd) {
                $retArr['code'] = $this->processCode->ok;
                $retArr['status'] = true;
                $retArr['message'] = $cachePwd;
            } else {
                $connInfo = $this->getConnect();
                if ($this->isNotConnect($connInfo)) {
                    return $retArr;
                }
                $this->queue->push('pwd', $this->queueHash);
                $getStreamRlt = $this->execCommand($connInfo);
                if ($getStreamRlt) {
                    $retArr['code'] = $this->processCode->ok;
                    $retArr['status'] = true;
                    $retArr['message'] = $this->renderPathTemplate($getStreamRlt);
                    $this->session->set_userdata('pwd', $retArr['message']);
                } else {
                    $retArr['code'] = $this->processCode->failGetStream;
                }
            }

            return $retArr;
        }

        private function decryptPassword(string $userPassword)
        {
            return openssl_decrypt($userPassword, getenv('method'), getenv('key'), true, getenv('iv'));
        }

        private function getConnect()
        {
            $connInfo = ssh2_connect(
                $this->userData->serverAddress,
                $this->userData->serverPort
            );

            if (!$connInfo) {
                return false;
            } else {
                ssh2_auth_password($connInfo, $this->userData->userId, $this->decryptPassword($this->userData->userPassword));

                return $connInfo;
            }
        }

        private function execCommand($link): string
        {
            $getStream = ssh2_exec($link, $this->queue->pop($this->queueHash));

            $getStdout = ssh2_fetch_stream($getStream, SSH2_STREAM_STDIO);
            $getErrout = ssh2_fetch_stream($getStream, SSH2_STREAM_STDERR);

            stream_set_blocking($getStdout, true);
            stream_set_blocking($getErrout, true);

            $getStdout = stream_get_contents($getStdout);

            if ($getStdout) {
                return trim($getStdout);
            } else {
                return trim(stream_get_contents($getErrout));
            }
        }

        private function isNotConnect($link)
        {
            return !$link;
        }

        private function updateCwd(array $splitCommand, int $len) : string
        {
            $retValue = '';
            if (2 === $len) {
                $retValue = $splitCommand[$len - 1];
            } else {
                $pathIdx = intval(key(array_filter($splitCommand = array_reverse($splitCommand), function ($elem) {
                    return strtolower($elem) === 'cd';
                }))) - 1;
                $retValue = $splitCommand[$pathIdx];
            }

            return trim($retValue);
        }

        public function execUserCommand(string $command): array
        {
            $retArr = [
                'status' => false,
                'code'   => $this->processCode->failConnect,
                'page'   => 'execCommand',
            ];
            $execFlag = true;
            $pwd = '';
            $execCommand = '';
            if (strpos($command, 'cd ') !== false) {
                if (2 === ($len = count($splitCommand = explode(' ', $command)))) {
                    $execFlag = false;
                }

                $pwd = $this->updateCwd($splitCommand, $len);
                $this->session->set_userdata([
                    'setDir' => 'cd '.$pwd.'; ',
                    'pwd'    => $this->renderPathTemplate($pwd),
                ]);
            }

            if ($execFlag) {
                $connInfo = $this->getConnect();
                if ($this->isNotConnect($connInfo)) {
                    return $retArr;
                }
                if ($this->session->setDir) {
                    $execCommand = $this->session->setDir.$command;
                } else {
                    $execCommand = $command;
                }
                $this->queue->push($execCommand, $this->queueHash);
                $getStreamRlt = $this->execCommand($connInfo);
                if ($getStreamRlt) {
                    $retArr['status'] = true;
                    $retArr['code'] = $this->processCode->ok;
                    $retArr['message'] = $getStreamRlt;
                } else {
                    $retArr['code'] = $this->processCode->failGetStream;
                }
            } else {
                $retArr['status'] = true;
                $retArr['code'] = $this->processCode->ok;
                $retArr['message'] = '';
                $retArr['pwd'] = $this->session->pwd;
            }

            return $retArr;
        }
    }
}
