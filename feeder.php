<?php

/**
 * PHP rss/atom feed reader/searcher
 *
 * @author     Petr Vavrin <pvavrin@gmail.com>
 * @version    1.0
 * @dependecy  CURL 
 * 
 * php feeder.php [url]
 * 
 * Internal commands:
 * list                      List all feeds
 * l                         List all feeds
 * search [search term]      Search term in feeds and list results
 * s [search term]           Search term in feeds and list results
 * help                      Show this help
 * h                         Show this help
 * quit                      Quit this script
 * q                         Quit this script
 */

// sample URL
// http://servis.idnes.cz/rss.aspx?c=zpravodaj
// http://bblfish.net/blog/blog.atom

class Parser
{
    private $url            = '';
    private $content        = '';
    private $document       = '';
    private $structure      = '';
    private $searchResult   = array();
    private $entryNames     = array('item', 'entry');
    private $utf8AsciiTable = Array(
        'ä' => 'a', 'Ä' => 'A', 'á' => 'a', 'Á' => 'A', 'à' => 'a', 'À' => 'A', 'ã' => 'a',
        'Ã' => 'A', 'â' => 'a', 'Â' => 'A', 'č' => 'c', 'Č' => 'C', 'ć' => 'c', 'Ć' => 'C',
        'ď' => 'd', 'Ď' => 'D', 'ě' => 'e', 'Ě' => 'E', 'é' => 'e', 'É' => 'E', 'ë' => 'e',
        'Ë' => 'E', 'è' => 'e', 'È' => 'E', 'ê' => 'e', 'Ê' => 'E', 'í' => 'i', 'Í' => 'I',
        'ï' => 'i', 'Ï' => 'I', 'ì' => 'i', 'Ì' => 'I', 'î' => 'i', 'Î' => 'I', 'ľ' => 'l',
        'Ľ' => 'L', 'ĺ' => 'l', 'Ĺ' => 'L', 'ń' => 'n', 'Ń' => 'N', 'ň' => 'n', 'Ň' => 'N',
        'ñ' => 'n', 'Ñ' => 'N', 'ó' => 'o', 'Ó' => 'O', 'ö' => 'o', 'Ö' => 'O', 'ô' => 'o',
        'Ô' => 'O', 'ò' => 'o', 'Ò' => 'O', 'õ' => 'o', 'Õ' => 'O', 'ő' => 'o', 'Ő' => 'O',
        'ř' => 'r', 'Ř' => 'R', 'ŕ' => 'r', 'Ŕ' => 'R', 'š' => 's', 'Š' => 'S', 'ś' => 's',
        'Ś' => 'S', 'ť' => 't', 'Ť' => 'T', 'ú' => 'u', 'Ú' => 'U', 'ů' => 'u', 'Ů' => 'U',
        'ü' => 'u', 'Ü' => 'U', 'ù' => 'u', 'Ù' => 'U', 'ũ' => 'u', 'Ũ' => 'U', 'û' => 'u',
        'Û' => 'U', 'ý' => 'y', 'Ý' => 'Y', 'ž' => 'z', 'Ž' => 'Z', 'ź' => 'z', 'Ź' => 'Z'
    );

    public function __construct ()
    {
        return $this;
    }

    public function setUrl ( $url )
    {
        $this->url = $url;
        return $this;
    }

    public function prepare ()
    {
        $this->downloadFile ();
        $this->parseContent ();
    }

    private function downloadFile ()
    {
        if ( $this->url != '' )
        {
            $ssl     = stripos ( $this->url, 'https://' ) === 0 ? true : false;
            $curlObj = curl_init ();
            $options = [
                CURLOPT_URL            => $this->url,
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_AUTOREFERER    => 1,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; MSIE 5.01; Windows NT 5.0)',
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => ['Expect:'],
            ];
            if ( $ssl )
            {
                $options[CURLOPT_SSL_VERIFYHOST] = false;
                $options[CURLOPT_SSL_VERIFYPEER] = false;
            }
            curl_setopt_array ( $curlObj, $options );

            $this->content = curl_exec ( $curlObj );

            file_put_contents ( "feed.xml", $this->content );

            curl_close ( $curlObj );
        }
        else if ( is_file ( 'feed.xml' ) )
        {
            $this->content = file_get_contents ( 'feed.xml' );
        }
        else
        {
            // error message - url and file is empty 
        }
    }

    private function parseContent ()
    {
        libxml_use_internal_errors ( true );

        $xml = simplexml_load_string ( $this->content );

        if ( $xml instanceof SimpleXMLElement )
        {
            $array           = json_decode ( json_encode ( (array) $xml ), 1 );
            $this->structure = array($xml->getName () => $array);
        }
    }

    private function searchRecursive ( $dataNode, $path, $searchTerm )
    {
        foreach ($dataNode as $nodeName => $nodeValue)
        {
            $nodePath = $path . "/" . strtolower ( $nodeName );

            if ( is_array ( $nodeValue ) )
            {
                $this->searchRecursive ( $nodeValue, $nodePath, $searchTerm );
            }
            else 
            { 
              $nodeValueEncoded = $this->consoleEncoding ( $nodeValue );

              if ( preg_match ( $searchTerm, $nodeValueEncoded ) )
              {
                  if ( preg_match ( "/(^.*(item|entry)\/\d+)\/(.*)$/", $nodePath, $match ) )
                  {
                      $this->searchResult[$match[1]][] = $match[3];
                  }
              }
            }
        }
    }

    private function selectRecursiveByPath ( $dataNode, $path, $searchPath )
    {
        $selectedNode = null;
        foreach ($dataNode as $nodeName => $nodeValue)
        {
            $nodePath = $path . "/" . strtolower ( $nodeName );
            if ( $nodePath == $searchPath )
            {
                return $nodeValue;
            }
            if ( is_array ( $nodeValue ) )
            {
                $selectedNode = $this->selectRecursiveByPath ( $nodeValue, $nodePath, $searchPath );
            }
        }
        return $selectedNode;
    }

    public function searchInStructure ( $searchTerm )
    {
        $searchTermQuote    = '/' . preg_quote ( $searchTerm ) . '/';
        $this->searchResult = array();
        $this->searchRecursive ( $this->structure, '', $searchTermQuote );

        foreach ( $this->searchResult as $resultPath => $keyNames )
        {
            $this->showEntry ( $resultPath, $keyNames );
        }
    }

    private function showEntry ( $path, $keyNames )
    {
        $entry = $this->selectRecursiveByPath ( $this->structure, '', $path );
        $title = $link  = '';
        if ( isset ( $entry['title'] ) )
        {
            $keysList = '';
            foreach ( $keyNames as $key )
            { 
              $keysList .= '[' . $key . '] '; 
            }  
            
            $title = $entry['title'];
            if ( isset ( $entry['link'] ) )
            {
                if ( isset ( $entry['link']['@attributes'] ) && isset ( $entry['link']['@attributes']['href'] ) )
                {
                    $link = $entry['link']['@attributes']['href'];
                }
                else
                {
                    $link = $entry['link'];
                }
            }
            echo str_repeat ( "-", 78 ) . "\n";
            echo $this->consoleEncoding ( $title ) . "\n" . $keysList . "\n\n" . $this->consoleEncoding ( $link ) . "\n";
        }
    }

    private function consoleEncoding ( $string )
    {
        return strtr ( $string, $this->utf8AsciiTable );
    }

    public function hasContent ()
    {
        return true;
    }

}

class cmdLiner
{
    private $quitCommand = 'quit';
    private $quitMessage = '';
    private $lineHeader  = 'feeder>';
    private $parser;

    public function __construct ()
    {
        $this->parser = new Parser();
        return $this;
    }

    public function mainLoop ()
    {
        if ( $this->parser->hasContent () == true )
        {
            $fp     = fopen ( 'php://stdin', 'r' );
            $in     = '';
            $isExit = false;
            while ( $isExit != true )
            {
                echo "\n" . $this->lineHeader;
                $in     = trim ( fgets ( $fp ) );
                $isExit = $this->parseCommand ( $in );
            }
        }
        else
        {
            echo "ERROR: Wrong content of input file\n";
            exit;
        }
    }

    public function setQuitCommand ( $command )
    {
        $this->quitCommand = $command;
        return $this;
    }

    public function setQuitMessage ( $message )
    {
        $this->quitMessage = $message;
        return $this;
    }

    public function setLineHeader ( $header )
    {
        $this->lineHeader = $header;
        return $this;
    }

    public function setFileName ( $fileName )
    {
        $this->fileName = $filename;
        return $this;
    }

    public function getArguments ()
    {
        if ( !isset ( $argv ) && isset ( $_SERVER ['argv'] ) )
        {
            $argv = $_SERVER ['argv'];
        }
        else
        {
            echo "Can't get command line URL argument\n";
            exit;
        }
        if ( isset ( $argv ) )
        {
            foreach ($argv as $k => $v)
            {
                if ( $k == 0 )
                    continue;
                if ( $k == 1 )
                {
                    $this->parser->setUrl ( $v );
                }
            }
        }
    }

    public function run ()
    {
        $this->parser->prepare ();
        $this->mainLoop ();
    }

    private function parseCommand ( $commandString )
    {
        $commandArray = explode ( ' ', $commandString );
        $mainCommand  = array_shift ( $commandArray );
        $countParams  = count ( $commandArray );

        switch ( strtolower ( $mainCommand ) )
        {
            case $this->quitCommand:
            case 'g':
                echo "Bye!\n";
                return true;

            case 'search':
            case 's':
                $this->parser->searchInStructure ( implode ( ' ', $commandArray ) );
                break;

            case 'list':
            case 'l':
                $this->parser->searchInStructure ( '' );
                break;

            case 'help':
            case 'h':
                $this->showHelp ();
                break;
             
            case '':
                break;
            
            default:
                echo "Unknown command - you can use 'help' command\n";
                break;
        }
        return false;
    }

    private function showHelp ()
    {
        $commandFormat = "%-26s%s\n";

        echo "--- Help ---\n"
        . 'php ' . basename ( $_SERVER ['SCRIPT_FILENAME'] ) . " [url]\n\n"
        . "Internal commands:\n"
        . sprintf ( $commandFormat, 'list', 'List all feeds' )
        . sprintf ( $commandFormat, 'l', 'List all feeds' )
        . sprintf ( $commandFormat, 'search [search term]', 'Search term in feeds and list results' )
        . sprintf ( $commandFormat, 's [search term]', 'Search term in feeds and list results' )
        . sprintf ( $commandFormat, 'help', 'Show this help' )
        . sprintf ( $commandFormat, 'h', 'Show this help' )
        . sprintf ( $commandFormat, $this->quitCommand, 'Quit this script' )
        . sprintf ( $commandFormat, 'q', 'Quit this script' )
        . "\n";
    }
}

$cmd = new cmdLiner ();
$cmd->getArguments ();
$cmd->run ();
