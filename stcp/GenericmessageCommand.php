<?php
namespace Longman\TelegramBot\Commands\SystemCommands;

use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;

/**
 * Generic message command
 *
 * Gets executed when any type of message is sent.
 *
 * In this message-related context, we can handle any kind of message.
 */
class GenericmessageCommand extends SystemCommand
{
    /**
     * @var string
     */
    protected $name = 'genericmessage';

    /**
     * @var string
     */
    protected $description = 'Próximas passagens na Paragem';

    /**
     * @var int
     */
    protected $tg_msg_maxsize = 4096;

    /**
     * @var string
     */
    protected $version = '0.1.0';

    /**
     * @var bool
     */
    protected $private_only = true;

    /**
     * Main command execution
     *
     * @return ServerResponse
     * @throws TelegramException
     */
    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $message_text = $message->getText(true);
        $user_id = $message->getFrom()->getId();
        $command = $message->getCommand();

        $reply = "";

        // TO-DO: usar /cmds talvez
        // determina se a string recebida é
        // 1 - Nr de linha STCP (3 digits)
        // 2 - Nome da paragem (outra string)
        if (is_numeric($message_text) && strlen($message_text) === 3) {
            // Nr da linha
            $linha = $message_text;
            $reply = "";
            $reply .= $this->stcp_paragens_by_linha($linha, 0); // sentido ->
            $reply .= PHP_EOL;
            $reply .= $this->stcp_paragens_by_linha($linha, 1); // sentido <-

        } else {
            // Nome da paragem
            $paragem = urlencode(strtoupper(htmlspecialchars($message_text)));
            $hash = $this->stcp_smsbus_hash($paragem);
            $reply = $this->stcp_timetables($paragem, $hash);
        }



        return $this->replyToChat($reply);
    }

    private $stcp_user_agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/116.0.0.0 Safari/537.36 Edge/116.0.1938.76';
    private function stcp_http_request($url)
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');

        $headers = [];
        $headers[] = "User-Agent: {$this->stcp_user_agent}";
        $headers[] = 'Accept: */*';
        $headers[] = 'Accept-Language: pt-PT,pt;q=0.5';
        $headers[] = 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8';
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        return $ch;
    }
    private $stcp_ep_hash = 'https://www.stcp.pt/pt/viajar/horarios/?t=smsbus&paragem=';
    private function stcp_smsbus_hash($paragem)
    {
        $url = $this->stcp_ep_hash . $paragem;

        $ch = $this->stcp_http_request($url);
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            return 'Error:' . curl_error($ch);
        }
        curl_close($ch);

        $re = '/sig\.getParagemInfo\(\'' . $paragem . '\', \'0\',\'(.+)\'\);/m';
        preg_match_all($re, $result, $matches, PREG_SET_ORDER, 0);

        return $matches[0][1];
    }
    private function stcp_timetables($paragem, $hash)
    {
        $stcp_ep_smsbus = "https://www.stcp.pt/pt/itinerarium/soapclient.php?codigo={$paragem}&linha=0&hash123={$hash}";

        $ch = $this->stcp_http_request($stcp_ep_smsbus);
        $htmlContent = curl_exec($ch);
        if (curl_errno($ch)) {
            return 'Error:' . curl_error($ch);
        }
        curl_close($ch);

        $dom = new \DOMDocument();
        $dom->loadHTML($htmlContent);
        $xpath = new \DOMXPath($dom);
        $busInfoString = "";

        // Find all table rows except the header row
        $rows = $xpath->query('//table[@id="smsBusResults"]/tr[position()>1]');

        foreach ($rows as $row) {
            $linha = trim($xpath->query('./td/ul/li/a', $row)->item(0)->nodeValue);
            $horaPrevista = trim($xpath->query('./td/i', $row)->item(0)->nodeValue);
            $tempoEspera = trim($xpath->query('./td[position()=3]', $row)->item(0)->nodeValue);

            if ($linha && $horaPrevista) {
                $busInfoString .= "Linha: " . $linha . "\n";
                $busInfoString .= "Hora Prevista: " . $horaPrevista . "\n";
                $busInfoString .= "ETA: " . $tempoEspera . "\n\n";
            }
        }

        if (empty($busInfoString))
            return 'Identificador de Paragem Inválido!';
        else
            return $busInfoString;
    }



    private function stcp_paragens_by_linha($linha, $sentido = 0)
    {
        $stcp_ep_paragens = "https://www.stcp.pt/pt/itinerarium/callservice.php?action=linestops&lcode={$linha}&ldir={$sentido}";

        $ch = $this->stcp_http_request($stcp_ep_paragens);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            return 'Error:' . curl_error($ch);
        }
        curl_close($ch);

        $data = json_decode($response, true);

        // primeira e ultima paragem
        $first = $data['records'][0]['name'];
        $last = end($data['records'])['name'];

        $paragens = "";
        $paragens .= "Linha {$linha}: {$first} - {$last}" . PHP_EOL;

        // Appending each element's name and code to the string variable
        foreach ($data['records'] as $record) {
            $paragens .= "- {$record['name']} [{$record['code']}]" . PHP_EOL;
        }

        return $paragens;
    }
}
