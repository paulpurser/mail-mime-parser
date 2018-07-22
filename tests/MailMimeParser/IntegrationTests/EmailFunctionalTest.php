<?php
namespace ZBateson\MailMimeParser\IntegrationTests;

use PHPUnit_Framework_TestCase;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message;
use ZBateson\MailMimeParser\Message\Part\MimePart;

/**
 * Description of EmailFunctionalTest
 *
 * @group Functional
 * @group EmailFunctionalTest
 * @author Zaahid Bateson
 */
class EmailFunctionalTest extends PHPUnit_Framework_TestCase
{
    private $parser;
    private $messageDir;
    
    // useful for testing an actual signed message with external tools -- the
    // tests may actually fail with this set to true though, as it always
    // tries to sign rather than verify a signature
    const USE_GPG_KEYGEN = false;

    protected function setUp()
    {
        $this->parser = new MailMimeParser();
        $this->messageDir = dirname(dirname(__DIR__)) . '/' . TEST_DATA_DIR . '/emails';
    }

    protected function assertStringEqualsIgnoreWhiteSpace($test, $str, $message = null)
    {
        $equal = (trim(preg_replace('/\s+/', ' ', $test)) === trim(preg_replace('/\s+/', ' ', $str)));
        if (!$equal) {
            file_put_contents(dirname(dirname(__DIR__)) . '/' . TEST_OUTPUT_DIR . "/fail_org", $test);
            file_put_contents(dirname(dirname(__DIR__)) . '/' . TEST_OUTPUT_DIR . "/fail_parsed", $str);
        }
        $this->assertTrue(
            $equal,
            $message . ' -- output written to _output/fail_org and _output/fail_parsed'
        );
    }

    protected function assertTextContentTypeEquals($expectedInputFileName, $actualInputStream, $message = null)
    {
        $str = stream_get_contents($actualInputStream);
        rewind($actualInputStream);
        $text = mb_convert_encoding(file_get_contents($this->messageDir . '/files/' . $expectedInputFileName), 'UTF-8', 'ISO-8859-1');
        $this->assertStringEqualsIgnoreWhiteSpace($text, $str, $message);
    }

    protected function assertHtmlContentTypeEquals($expectedInputFileName, $actualInputStream, $message = null)
    {
        $str = html_entity_decode(str_replace('&nbsp;', ' ', strip_tags(stream_get_contents($actualInputStream))));
        rewind($actualInputStream);
        $text = mb_convert_encoding(file_get_contents($this->messageDir . '/files/' . $expectedInputFileName), 'UTF-8', 'ISO-8859-1');
        $this->assertStringEqualsIgnoreWhiteSpace($text, $str, $message);
    }

    private function runEmailTestForMessage($message, array $props, $failMessage)
    {
        if (isset($props['text'])) {
            $f = $message->getTextStream();
            $this->assertNotNull($f, $failMessage);
            $this->assertTextContentTypeEquals($props['text'], $f, $failMessage);
        }

        if (isset($props['html'])) {
            $f = $message->getHtmlStream();
            $this->assertNotNull($f, $failMessage);
            $this->assertHtmlContentTypeEquals($props['html'], $f, $failMessage);
        }

        if (isset($props['To']['email'])) {
            $to = $message->getHeader('To');
            if (isset($props['To']['name'])) {
                $this->assertEquals($props['To']['name'], $to->getPersonName(), $failMessage);
            }
            $this->assertEquals($props['To']['email'], $to->getValue(), $failMessage);
        }

        if (isset($props['From']['email'])) {
            $from = $message->getHeader('From');
            if (isset($props['From']['name'])) {
                $this->assertNotNull($from, $failMessage);
                $this->assertEquals($props['From']['name'], $from->getPersonName(), $failMessage);
            }
            $this->assertEquals($props['From']['email'], $from->getValue(), $failMessage);
        }

        if (isset($props['Subject'])) {
            $this->assertEquals($props['Subject'], $message->getHeaderValue('subject'), $failMessage);
        }

        if (!empty($props['signed'])) {
            $this->assertEquals('multipart/signed', $message->getHeaderValue('Content-Type'), $failMessage);
            $protocol = $message->getHeaderParameter('Content-Type', 'protocol');
            $micalg = $message->getHeaderParameter('Content-Type', 'micalg');
            $signedPart = $message->getSignaturePart();
            $this->assertEquals($props['signed']['protocol'], $protocol, $failMessage);
            $this->assertEquals($props['signed']['micalg'], $micalg, $failMessage);
            $this->assertNotNull($signedPart, $failMessage);
            $signedPartProtocol = $props['signed']['protocol'];
            if (!empty($props['signed']['signed-part-protocol'])) {
                $signedPartProtocol = $props['signed']['signed-part-protocol'];
            }
            $this->assertEquals($signedPartProtocol, $signedPart->getHeaderValue('Content-Type'), $failMessage);
            $this->assertEquals(trim($props['signed']['body']), trim($signedPart->getContent()));
        }

        if (!empty($props['attachments'])) {
            $this->assertEquals($props['attachments'], $message->getAttachmentCount(), $failMessage);
            $attachments = $message->getAllAttachmentParts();
            foreach ($attachments as $attachment) {
                $name = $name = $attachment->getFilename();
                if (!empty($name) && file_exists($this->messageDir . '/files/' . $name)) {

                    if ($attachment->getContentType() === 'text/html') {
                        $this->assertHtmlContentTypeEquals(
                            $name,
                            $attachment->getContentResourceHandle(),
                            'HTML content is not equal'
                        );
                    } elseif (stripos($attachment->getContentType(), 'text/') === 0) {
                        $this->assertTextContentTypeEquals(
                            $name,
                            $attachment->getContentResourceHandle(),
                            'Text content is not equal'
                        );
                    } else {
                        $file = file_get_contents($this->messageDir . '/files/' . $name);
                        $handle = $attachment->getContentResourceHandle();
                        $att = stream_get_contents($handle);
                        rewind($handle);
                        $equal = ($file === $att);
                        if (!$equal) {
                            file_put_contents(dirname(dirname(__DIR__)) . '/' . TEST_OUTPUT_DIR . "/{$name}_fail_org", $file);
                            file_put_contents(dirname(dirname(__DIR__)) . '/' . TEST_OUTPUT_DIR . "/{$name}_fail_parsed", $att);
                        }
                        $this->assertTrue(
                            $equal,
                            $failMessage . " -- output written to _output/{$name}_fail_org and _output/{$name}_fail_parsed"
                        );
                    }
                }
            }
        }
        if (!empty($props['parts'])) {
            $this->runPartsTests($message, $props['parts'], $failMessage);
        }
    }
    
    private function runPartsTests($part, array $types, $failMessage)
    {
        $this->assertNotNull($part, $failMessage);
        $this->assertNotNull($types);
        foreach ($types as $key => $type) {
            if (is_array($type)) {
                $this->assertEquals(
                    strtolower($key),
                    $part->getContentType(),
                    $failMessage
                );
                $this->assertInstanceOf('ZBateson\MailMimeParser\Message\Part\MimePart', $part);
                $cparts = $part->getChildParts();
                $curPart = current($cparts);
                $this->assertCount(count($type), $cparts, $failMessage);
                foreach ($type as $key => $ctype) {
                    $this->runPartsTests($curPart, [ $key => $ctype ], $failMessage);
                    $curPart = next($cparts);
                }
            } else {
                if ($part instanceof MimePart) {
                    $this->assertEmpty($part->getChildParts(), $failMessage);
                }
                $this->assertEquals(
                    strtolower($type),
                    strtolower($part->getContentType()),
                    $failMessage
                );
            }
        }
    }

    private function runEmailTest($key, array $props) {
        $handle = fopen($this->messageDir . '/' . $key . '.txt', 'r');
        $message = $this->parser->parse($handle);
        fclose($handle);

        $failMessage = 'Failed while parsing ' . $key;
        $this->runEmailTestForMessage($message, $props, $failMessage);

        $tmpSaved = fopen(dirname(dirname(__DIR__)) . '/' . TEST_OUTPUT_DIR . "/$key", 'w+');
        $message->save($tmpSaved);
        rewind($tmpSaved);

        $messageWritten = $this->parser->parse($tmpSaved);
        fclose($tmpSaved);
        $failMessage = 'Failed while parsing saved message for ' . $key;
        $this->runEmailTestForMessage($messageWritten, $props, $failMessage);
    }

    private function getSignatureForContent($signableContent)
    {
        if (static::USE_GPG_KEYGEN) {
            $command = 'gpg --sign --detach-sign --armor --cipher-algo AES256 --digest-algo SHA256 --textmode --lock-never';
            $descriptorspec = [
                0 => ["pipe", "r"],
                1 => ["pipe", "w"],
                2 => ["pipe", "r"]
            ];
            $cwd = sys_get_temp_dir();
            $proc = proc_open($command, $descriptorspec, $pipes, $cwd);
            fwrite($pipes[0], $signableContent);
            fclose($pipes[0]);
            $signature = trim(stream_get_contents($pipes[1]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            return preg_replace('/\r|\n/', '', $signature);
        } else {
            return md5($signableContent);
        }
    }

    public function testParseEmailm0001()
    {
        $this->runEmailTest('m0001', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@example.com'
            ],
            'To' => [
                'name' => 'Jürgen Schmürgen',
                'email' => 'schmuergen@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche (Microsoft Outlook 00)',
            'text' => 'HasenundFrosche.txt',
            'parts' => [
                'text/plain'
            ],
        ]);
    }

    public function testParseEmailm0002()
    {
        $this->runEmailTest('m0002', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@example.com'
            ],
            'To' => [
                'name' => 'Jürgen Schmürgen',
                'email' => 'schmuergen@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche (Microsoft Outlook 00)',
            'text' => 'HasenundFrosche.txt',
            'parts' => [
                'text/plain'
            ],
        ]);
    }

    public function testParseEmailm0003()
    {
        $this->runEmailTest('m0003', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@example.com'
            ],
            'To' => [
                'name' => 'Jürgen Schmürgen',
                'email' => 'schmuergen@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche (Microsoft Outlook 00)',
            'text' => 'HasenundFrosche.txt',
            'parts' => [
                'text/plain'
            ],
        ]);
    }

    public function testParseEmailm0004()
    {
        $this->runEmailTest('m0004', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@example.com'
            ],
            'To' => [
                'name' => 'Jürgen Schmürgen',
                'email' => 'schmuergen@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche (Microsoft Outlook 00)',
            'text' => 'HasenundFrosche.txt',
            'parts' => [
                'text/plain'
            ],
        ]);
    }

    public function testParseEmailm0005()
    {
        $this->runEmailTest('m0005', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@example.com'
            ],
            'To' => [
                'name' => 'Jürgen Schmürgen',
                'email' => 'schmuergen@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche (Microsoft Outlook 00)',
            'text' => 'HasenundFrosche.txt',
            'parts' => [
                'text/plain'
            ],
        ]);
    }

    public function testParseEmailm0006()
    {
        $this->runEmailTest('m0006', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@example.com'
            ],
            'To' => [
                'name' => 'Joe Blow',
                'email' => 'jblow@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche (Microsoft Outlook 00)',
            'text' => 'HasenundFrosche.txt',
            'parts' => [
                'text/plain'
            ],
        ]);
    }

    public function testParseEmailm0007()
    {
        $this->runEmailTest('m0007', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@example.com'
            ],
            'To' => [
                'name' => 'Heinz Müller',
                'email' => 'mueller@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche (Microsoft Outlook 00)',
            'text' => 'HasenundFrosche.txt',
            'parts' => [
                'text/plain'
            ],
        ]);
    }

    public function testParseEmailm0008()
    {
        $this->runEmailTest('m0008', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@example.com'
            ],
            'To' => [
                'name' => 'Heinz Müller',
                'email' => 'mueller@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche (Microsoft Outlook 00)',
            'text' => 'HasenundFrosche.txt',
            'parts' => [
                'text/plain'
            ],
        ]);
    }

    public function testParseEmailm0009()
    {
        $this->runEmailTest('m0009', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@example.com'
            ],
            'To' => [
                'name' => 'Heinz Müller',
                'email' => 'mueller@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche (Microsoft Outlook 00)',
            'text' => 'HasenundFrosche.txt',
            'parts' => [
                'text/plain'
            ],
        ]);
    }

    public function testParseEmailm0010()
    {
        $this->runEmailTest('m0010', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@example.com'
            ],
            'To' => [
                'name' => 'Heinz Müller',
                'email' => 'mueller@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche (Microsoft Outlook 00)',
            'text' => 'HasenundFrosche.txt',
            'parts' => [
                'text/plain'
            ],
        ]);
    }

    public function testParseEmailm0011()
    {
        $this->runEmailTest('m0011', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@example.com'
            ],
            'To' => [
                'name' => 'Heinz Müller',
                'email' => 'mueller@example.com'
            ],
            'Subject' => 'Test message from Microsoft Outlook 00',
            'text' => 'hareandtortoise.txt',
            'attachments' => 3,
            'parts' => [
                'multipart/mixed' => [
                    'text/plain',
                    'image/png',
                    'image/png',
                    'image/png'
                ]
            ],
        ]);
    }

    public function testParseEmailm0012()
    {
        $this->runEmailTest('m0012', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@example.com'
            ],
            'To' => [
                'name' => 'Joe Blow',
                'email' => 'jblow@example.com'
            ],
            'Subject' => 'Test message from Microsoft Outlook 00',
            'attachments' => 1,
            'parts' => [
                'image/png',
            ],
        ]);
    }

    public function testParseEmailm0013()
    {
        $this->runEmailTest('m0013', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@example.com'
            ],
            'To' => [
                'name' => 'Joe Blow',
                'email' => 'jblow@example.com'
            ],
            'Subject' => 'Test message from Microsoft Outlook 00',
            'attachments' => 2,
            'parts' => [
                'multipart/mixed' => [
                    'image/png',
                    'image/png',
                ]
            ],
        ]);
    }

    public function testParseEmailm0014()
    {
        $this->runEmailTest('m0014', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@example.com'
            ],
            'To' => [
                'name' => 'Joe Blow',
                'email' => 'jblow@example.com'
            ],
            'Subject' => 'Test message from Microsoft Outlook 00',
            'text' => 'hareandtortoise.txt',
            'html' => 'hareandtortoise.txt',
            'parts' => [
                'multipart/alternative' => [
                    'text/plain',
                    'text/html'
                ]
            ]
        ]);
    }

    public function testParseEmailm0015()
    {
        $this->runEmailTest('m0015', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@example.com'
            ],
            'To' => [
                'name' => 'Joe Blow',
                'email' => 'jblow@example.com'
            ],
            'Subject' => 'Test message from Microsoft Outlook 00',
            'text' => 'hareandtortoise.txt',
            'html' => 'hareandtortoise.txt',
            'attachments' => 2,
            'parts' => [
                'multipart/mixed' => [
                    'multipart/alternative' => [
                        'text/plain',
                        'text/html'
                    ],
                    'image/png',
                    'image/png'
                ]
            ]
        ]);
    }

    public function testParseEmailm0016()
    {
        $this->runEmailTest('m0016', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@example.com'
            ],
            'To' => [
                'name' => 'Joe Blow',
                'email' => 'jblow@example.com'
            ],
            'Subject' => 'Test message from Microsoft Outlook 00',
            'text' => 'hareandtortoise.txt',
            'html' => 'hareandtortoise.txt',
            'attachments' => 2,
            'parts' => [
                'multipart/related' => [
                    'multipart/alternative' => [
                        'text/plain',
                        'text/html'
                    ],
                    'image/png',
                    'image/png'
                ]
            ]
        ]);
    }

    public function testParseEmailm0017()
    {
        $this->runEmailTest('m0017', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@example.com'
            ],
            'To' => [
                'name' => 'Joe Blow',
                'email' => 'jblow@example.com'
            ],
            'Subject' => 'Test message from Microsoft Outlook 00',
            'text' => 'hareandtortoise.txt',
            'html' => 'hareandtortoise.txt',
            'attachments' => 3,
            'parts' => [
                'multipart/mixed' => [
                    'multipart/related' => [
                        'multipart/alternative' => [
                            'text/plain',
                            'text/html'
                        ],
                        'image/png'
                    ],
                    'image/png',
                    'image/png'
                ]
            ]
        ]);
    }

    public function testParseEmailm0018()
    {
        $this->runEmailTest('m0018', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@example.com'
            ],
            'To' => [
                'name' => 'Joe Blow',
                'email' => 'jblow@example.com'
            ],
            'Subject' => 'Test message from Microsoft Outlook 00',
            'text' => 'hareandtortoise.txt',
            'attachments' => 3,
            'parts' => [
                'text/plain' => [
                    'application/octet-stream',
                    'application/octet-stream',
                    'application/octet-stream'
                ]
            ]
        ]);
    }

    public function testParseEmailm0019()
    {
        $this->runEmailTest('m0019', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@example.com'
            ],
            'To' => [
                'name' => 'Joe Blow',
                'email' => 'jblow@example.com'
            ],
            'Subject' => 'Test message from Microsoft Outlook 00',
            'text' => 'hareandtortoise.txt',
            'html' => 'hareandtortoise.txt',
            'attachments' => 2,
        ]);
    }

    public function testParseEmailm0020()
    {
        $this->runEmailTest('m0020', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@example.com'
            ],
            'To' => [
                'name' => 'Joe Blow',
                'email' => 'jblow@example.com'
            ],
            'Subject' => 'Test message from Microsoft Outlook 00',
            'text' => 'hareandtortoise.txt',
            'html' => 'hareandtortoise.txt',
            'attachments' => 2,
        ]);
    }

    public function testParseEmailm0021()
    {
        $this->runEmailTest('m0021', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@example.com'
            ],
            'To' => [
                'name' => 'Heinz Müller',
                'email' => 'mueller@example.com'
            ],
            'Subject' => 'Test message from Microsoft Outlook 00',
            'text' => 'hareandtortoise.txt',
            'attachments' => 3,
            'parts' => [
                'multipart/mixed' => [
                    'text/plain',
                    'image/png',
                    'image/png',
                    'image/png'
                ]
            ],
        ]);
    }

    public function testParseEmailm1001()
    {
        $this->runEmailTest('m1001', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Jürgen Schmürgen',
                'email' => 'schmuergen@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche (Netscape Communicator 4.7)',
            'text' => 'HasenundFrosche.txt',
        ]);
    }

    public function testParseEmailm1002()
    {
        $this->runEmailTest('m1002', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Heinz Müller',
                'email' => 'mueller@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche (Netscape Communicator 4.7)',
            'text' => 'HasenundFrosche.txt',
            'html' => 'HasenundFrosche.txt',
        ]);
    }

    public function testParseEmailm1003()
    {
        $this->runEmailTest('m1003', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Joe Blow',
                'email' => 'blow@example.com'
            ],
            'Subject' => 'Test message from Netscape Communicator 4.7',
            'text' => 'HasenundFrosche.txt',
            'attachments' => 3,
        ]);
    }

    public function testParseEmailm1004()
    {
        $this->runEmailTest('m1004', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Joe Blow',
                'email' => 'blow@example.com'
            ],
            'Subject' => 'Test message from Netscape Communicator 4.7',
            'text' => 'HasenundFrosche.txt',
            'html' => 'HasenundFrosche.txt',
            'attachments' => 2,
        ]);
    }

    public function testParseEmailm1005()
    {
        $this->runEmailTest('m1005', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Heinz Müller',
                'email' => 'mueller@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche (Netscape Messenger 4.7)',
            'html' => 'HasenundFrosche.txt',
            'attachments' => 4,
        ]);
    }

    public function testParseEmailm1006()
    {
        $this->runEmailTest('m1006', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Jürgen Schmürgen',
                'email' => 'schmuergen@example.com'
            ],
            'Subject' => 'Test message from Netscape Communicator 4.7',
            'html' => 'HasenundFrosche.txt',
            'attachments' => 4,
        ]);
    }

    public function testParseEmailm1007()
    {
        $this->runEmailTest('m1007', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Joe Blow',
                'email' => 'blow@example.com'
            ],
            'Subject' => 'Test message from Netscape Communicator 4.7',
            'text' => 'hareandtortoise.txt'
        ]);
    }

    public function testParseEmailm1008()
    {
        $this->runEmailTest('m1008', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Joe Blow',
                'email' => 'blow@example.com'
            ],
            'Subject' => 'Test message from Netscape Communicator 4.7',
            'text' => 'hareandtortoise.txt'
        ]);
    }

    public function testParseEmailm1009()
    {
        $this->runEmailTest('m1009', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Joe Blow',
                'email' => 'blow@example.com'
            ],
            'Subject' => 'Test message from Netscape Communicator 4.7',
            'text' => 'hareandtortoise.txt',
            'attachments' => 2,
        ]);
    }

    /*
     * m1010.txt looks like it's badly encoded.  Was it really sent like that?
     */
    public function testParseEmailm1010()
    {
        $handle = fopen($this->messageDir . '/m1010.txt', 'r');
        $message = $this->parser->parse($handle);
        fclose($handle);

        $failMessage = 'Failed while parsing m1010';
        $message->setCharsetOverride('iso-8859-1');
        $f = $message->getTextStream(0);
        $this->assertNotNull($f, $failMessage);
        $this->assertTextContentTypeEquals('HasenundFrosche.txt', $f, $failMessage);
    }

    /*
     * m1011.txt looks like it's badly encoded.  Was it really sent like that?
     * Can't find what the file could be...
     */
    /*
    public function testParseEmailm1011()
    {
        $this->runEmailTest('m1011', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Heinz Müller',
                'email' => 'mueller@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche',
            'text' => 'HasenundFrosche.txt',
        ]);
    }*/

    public function testParseEmailm1012()
    {
        $this->runEmailTest('m1012', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Jürgen Schmürgen',
                'email' => 'schmuergen@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche',
            'text' => 'HasenundFrosche.txt',
        ]);
    }

    public function testParseEmailm1013()
    {
        $this->runEmailTest('m1013', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Joe Blow',
                'email' => 'blow@example.com'
            ],
            'Subject' => 'Test message from Netscape Communicator 4.7',
            'attachments' => 1
        ]);
    }

    public function testParseEmailm1014()
    {
        $this->runEmailTest('m1014', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Joe Blow',
                'email' => 'blow@example.com'
            ],
            'Subject' => 'Test message from Netscape Communicator 4.7',
            'text' => 'hareandtortoise.txt',
            'attachments' => 3
        ]);
    }

    public function testParseEmailm1015()
    {
        $this->runEmailTest('m1015', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Joe Blow',
                'email' => 'blow@example.com'
            ],
            'Subject' => 'Test message from Netscape Communicator 4.7',
            'text' => 'hareandtortoise.txt'
        ]);
        $handle = fopen($this->messageDir . '/m1015.txt', 'r');
        $message = $this->parser->parse($handle);
        fclose($handle);

        $stream = $message->getTextStream(1);
        $this->assertTextContentTypeEquals('HasenundFrosche.txt', $stream);
    }

    public function testParseEmailm1016()
    {
        $this->runEmailTest('m1016', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Joe Blow',
                'email' => 'blow@example.com'
            ],
            'Subject' => 'Test message from Netscape Communicator 4.7',
            'text' => 'hareandtortoise.txt',
        ]);
        $handle = fopen($this->messageDir . '/m1016.txt', 'r');
        $message = $this->parser->parse($handle);
        fclose($handle);

        $stream = $message->getTextStream(1);
        $str = $this->assertTextContentTypeEquals('farmerandstork.txt', $stream);
    }

    public function testParseEmailm2001()
    {
        $this->runEmailTest('m2001', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Jürgen Schmürgen',
                'email' => 'jschmuergen@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche',
            'text' => 'HasenundFrosche.txt',
        ]);
    }

    public function testParseEmailm2002()
    {
        $this->runEmailTest('m2002', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Heinz Müller',
                'email' => 'mueller@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche',
            'text' => 'HasenundFrosche.txt',
            'html' => 'HasenundFrosche.txt',
        ]);
    }

    public function testParseEmailm2003()
    {
        $this->runEmailTest('m2003', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Heinz Müller',
                'email' => 'mueller@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche',
            'html' => 'HasenundFrosche.txt',
        ]);
    }

    public function testParseEmailm2004()
    {
        $this->runEmailTest('m2004', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Heinz Müller',
                'email' => 'mueller@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche',
            'html' => 'HasenundFrosche.txt',
            'attachments' => 2,
        ]);
    }

    public function testParseEmailm2005()
    {
        $this->runEmailTest('m2005', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Heinz Müller',
                'email' => 'mueller@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche',
            'html' => 'HasenundFrosche.txt',
            // 'text' => 'HasenundFrosche.txt', - contains extra text at the end
            'attachments' => 4,
        ]);
    }

    public function testParseEmailm2006()
    {
        $this->runEmailTest('m2006', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Heinz Müller',
                'email' => 'mueller@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche',
            'html' => 'HasenundFrosche.txt',
            'attachments' => 2,
        ]);
    }

    public function testParseEmailm2007()
    {
        $this->runEmailTest('m2007', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Heinz Müller',
                'email' => 'mueller@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche',
            'html' => 'HasenundFrosche.txt',
            'attachments' => 4,
        ]);
    }

    public function testParseEmailm2008()
    {
        $this->runEmailTest('m2008', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Heinz Müller',
                'email' => 'mueller@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche',
            //'text' => 'HasenundFrosche.txt', contains extra text at the end
            'attachments' => 4,
        ]);
    }

    public function testParseEmailm2009()
    {
        $this->runEmailTest('m2009', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Heinz Müller',
                'email' => 'mueller@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche',
            'html' => 'HasenundFrosche.txt',
            'attachments' => 2,
        ]);
    }

    public function testParseEmailm2010()
    {
        $this->runEmailTest('m2010', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Jürgen Schmürgen',
                'email' => 'jschmuergen@example.com'
            ],
            'Subject' => 'The Hare and the Tortoise',
            'text' => 'hareandtortoise.txt',
            'attachments' => 2,
        ]);
    }

    public function testParseEmailm2011()
    {
        $this->runEmailTest('m2011', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Jürgen Schmürgen',
                'email' => 'jschmuergen@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche',
            'text' => 'HasenundFrosche.txt',
            //'attachments' => 2, - attachments are "binhex" encoded
        ]);
    }

    public function testParseEmailm2012()
    {
        $this->runEmailTest('m2012', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Jürgen Schmürgen',
                'email' => 'jschmuergen@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche',
            'text' => 'HasenundFrosche.txt',
            'attachments' => 3,
        ]);
    }

    public function testParseEmailm2013()
    {
        $this->runEmailTest('m2013', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Heinz Müller',
                'email' => 'mueller@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche',
            'text' => 'HasenundFrosche.txt',
            'attachments' => 2
        ]);
    }

    public function testParseEmailm2014()
    {
        $this->runEmailTest('m2014', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Jürgen Schmürgen',
                'email' => 'jschmuergen@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche',
            'text' => 'HasenundFrosche.txt'
        ]);
    }

    public function testParseEmailm2015()
    {
        $this->runEmailTest('m2015', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Heinz Müller',
                'email' => 'mueller@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche',
            'text' => 'HasenundFrosche.txt',
        ]);
    }

    public function testParseEmailm2016()
    {
        $this->runEmailTest('m2016', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Joe Blow',
                'email' => 'blow@example.com'
            ],
            'Subject' => 'The Hare and the Tortoise',
            'text' => 'hareandtortoise.txt',
        ]);
    }

    public function testParseEmailm3001()
    {
        $this->runEmailTest('m3001', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@penguin.example.com'
            ],
            'To' => [
                'name' => 'Joe Blow',
                'email' => 'blow@example.com'
            ],
            'Subject' => 'Test message from PINE',
            'attachments' => 2,
        ]);
    }

    public function testParseEmailm3002()
    {
        $this->runEmailTest('m3002', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@penguin.example.com'
            ],
            'To' => [
                'name' => 'Jürgen Schmürgen',
                'email' => 'schmuergen@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche',
            'text' => 'HasenundFrosche.txt',
        ]);
    }

    public function testParseEmailm3003()
    {
        $this->runEmailTest('m3003', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@penguin.example.com'
            ],
            'To' => [
                'name' => 'Heinz Müller',
                'email' => 'mueller@example.com'
            ],
            'Subject' => 'PNG graphic',
            'attachments' => 1,
        ]);
    }

    public function testParseEmailm3004()
    {
        $this->runEmailTest('m3004', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@penguin.example.com'
            ],
            'To' => [
                'name' => 'Joe Blow',
                'email' => 'blow@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche',
            // 'attachments' => 1, filename part is weird
        ]);
    }

    public function testParseEmailm3005()
    {
        $this->runEmailTest('m3005', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Jürgen Schmürgen',
                'email' => 'jschmuergen@example.com'
            ],
            'Subject' => 'The Hare and the Tortoise',
            'text' => 'hareandtortoise.txt',
            'attachments' => 1
        ]);
    }

    public function testParseEmailm3006()
    {
        $this->runEmailTest('m3006', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Jürgen Schmürgen',
                'email' => 'jschmuergen@example.com'
            ],
            'Subject' => 'The Hare and the Tortoise',
            'text' => 'hareandtortoise.txt',
            'attachments' => 1
        ]);
    }

    public function testParseEmailm3007()
    {
        $this->runEmailTest('m3007', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Jürgen Schmürgen',
                'email' => 'jschmuergen@example.com'
            ],
            'Subject' => 'The Hare and the Tortoise',
            'text' => 'hareandtortoise.txt',
            'attachments' => 3,
        ]);
    }

    public function testParseFromStringm0001()
    {
        $str = file_get_contents($this->messageDir . '/m0001.txt');
        $message = Message::from($str);
        $this->runEmailTestForMessage($message, [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@example.com'
            ],
            'To' => [
                'name' => 'Jürgen Schmürgen',
                'email' => 'schmuergen@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche (Microsoft Outlook 00)',
            'text' => 'HasenundFrosche.txt'
        ], 'Failed to parse m0001 from a string');
    }
    
    public function testVerifySignedEmailm4001()
    {
        $handle = fopen($this->messageDir . '/m4001.txt', 'r');
        $message = $this->parser->parse($handle);
        fclose($handle);

        $this->assertInstanceOf('ZBateson\MailMimeParser\SignedMessage', $message);
        $testString = $message->getSignedMessageAsString();
        $this->assertEquals(md5($testString), trim($message->getSignaturePart()->getContent()));
    }
    
    public function testParseEmailm4001()
    {
        $this->runEmailTest('m4001', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@example.com'
            ],
            'To' => [
                'name' => 'Jürgen Schmürgen',
                'email' => 'schmuergen@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche (Microsoft Outlook 00)',
            'text' => 'HasenundFrosche.txt',
            'signed' => [
                'protocol' => 'application/pgp-signature',
                'micalg' => 'pgp-sha256',
                'body' => '9825cba003a7ac85b9a3f3dc9f8423fd'
            ],
        ]);
    }

    public function testVerifySignedEmailm4002()
    {
        $handle = fopen($this->messageDir . '/m4002.txt', 'r');
        $message = $this->parser->parse($handle);
        fclose($handle);
        
        $this->assertInstanceOf('ZBateson\MailMimeParser\SignedMessage', $message);
        $testString = $message->getSignedMessageAsString();
        $this->assertEquals(md5($testString), trim($message->getSignaturePart()->getContent()));
    }

    public function testParseEmailm4002()
    {
        $this->runEmailTest('m4002', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@example.com'
            ],
            'To' => [
                'name' => 'Heinz Müller',
                'email' => 'mueller@example.com'
            ],
            'Subject' => 'Test message from Microsoft Outlook 00',
            'text' => 'hareandtortoise.txt',
            'attachments' => 3,
            'signed' => [
                'protocol' => 'application/pgp-signature',
                'micalg' => 'md5',
                'body' => 'f691886408cbeedc753548d2d198bf92'
            ],
        ]);
    }
    
    public function testVerifySignedEmailm4003()
    {
        $handle = fopen($this->messageDir . '/m4003.txt', 'r');
        $message = $this->parser->parse($handle);
        fclose($handle);
        
        $this->assertInstanceOf('ZBateson\MailMimeParser\SignedMessage', $message);
        $testString = $message->getSignedMessageAsString();
        $this->assertEquals(md5($testString), trim($message->getSignaturePart()->getContent()));
    }

    public function testParseEmailm4003()
    {
        $this->runEmailTest('m4003', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@example.com'
            ],
            'To' => [
                'name' => 'Joe Blow',
                'email' => 'jblow@example.com'
            ],
            'Subject' => 'Test message from Microsoft Outlook 00',
            'text' => 'hareandtortoise.txt',
            'html' => 'hareandtortoise.txt',
            'signed' => [
                'protocol' => 'application/pgp-signature',
                'micalg' => 'pgp-sha256',
                'body' => 'ba0ce5fac600d1a2e1f297d0040b858c'
            ],
        ]);
    }
    
    public function testVerifySignedEmailm4004()
    {
        $handle = fopen($this->messageDir . '/m4004.txt', 'r');
        $message = $this->parser->parse($handle);
        fclose($handle);
        
        $this->assertInstanceOf('ZBateson\MailMimeParser\SignedMessage', $message);
        $testString = $message->getSignedMessageAsString();
        $this->assertEquals(md5($testString), trim($message->getSignaturePart()->getContent()));
    }

    public function testParseEmailm4004()
    {
        $this->runEmailTest('m4004', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Heinz Müller',
                'email' => 'mueller@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche (Netscape Messenger 4.7)',
            'html' => 'HasenundFrosche.txt',
            'attachments' => 4,
            'signed' => [
                'protocol' => 'application/pgp-signature',
                'micalg' => 'pgp-sha256',
                'body' => 'eb4c0347d13a2bf71a3f9673c4b5e3db'
            ],
        ]);
    }

    public function testParseEmailm4005()
    {
        $handle = fopen($this->messageDir . '/m4005.txt', 'r');
        $message = $this->parser->parse($handle);
        fclose($handle);

        $str = file_get_contents($this->messageDir . '/files/blueball.png');
        $this->assertEquals(1, $message->getAttachmentCount());
        $this->assertEquals('text/rtf', $message->getAttachmentPart(0)->getHeaderValue('Content-Type'));
        $this->assertTrue($str === $message->getAttachmentPart(0)->getContent(), 'text/rtf stream doesn\'t match binary stream');

        $props = [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'doug@example.com'
            ],
            'To' => [
                'name' => 'Heinz Müller',
                'email' => 'mueller@example.com'
            ],
            'Subject' => 'Test message from Microsoft Outlook 00',
            'text' => 'hareandtortoise.txt'
        ];

        $this->runEmailTestForMessage($message, $props, 'failed parsing m4005');
        $tmpSaved = fopen(dirname(dirname(__DIR__)) . '/' . TEST_OUTPUT_DIR . "/m4005", 'w+');
        $message->save($tmpSaved);
        rewind($tmpSaved);

        $messageWritten = $this->parser->parse($tmpSaved);
        fclose($tmpSaved);
        $failMessage = 'Failed while parsing saved message for adding a large attachment to m0001';
        $this->runEmailTestForMessage($messageWritten, $props, $failMessage);

        $this->assertEquals(1, $messageWritten->getAttachmentCount());
        $this->assertEquals('text/rtf', $messageWritten->getAttachmentPart(0)->getHeaderValue('Content-Type'));
        $this->assertTrue($str === $messageWritten->getAttachmentPart(0)->getContent(), 'text/rtf stream doesn\'t match binary stream');
    }

    public function testParseEmailm4006()
    {
        $this->runEmailTest('m4006', [
            'From' => [
                'name' => 'Test Sender',
                'email' => 'sender@email.test'
            ],
            'To' => [
                'name' => 'Test Recipient',
                'email' => 'recipient@email.test'
            ],
            'Subject' => 'Read: invitation',
            'attachments' => 1,
        ]);
    }

    public function testParseEmailm4007()
    {
        $this->runEmailTest('m4007', [
            'From' => [
                'name' => 'Test Sender',
                'email' => 'sender@email.test'
            ],
            'To' => [
                'name' => 'Test Recipient',
                'email' => 'recipient@email.test'
            ],
            'Subject' => 'Test multipart-digest',
            'attachments' => 1,
        ]);
    }
    
    public function testVerifySignedEmailm4008()
    {
        $handle = fopen($this->messageDir . '/m4008.txt', 'r');
        $message = $this->parser->parse($handle);
        fclose($handle);
        
        $this->assertInstanceOf('ZBateson\MailMimeParser\SignedMessage', $message);
        $testString = $message->getSignedMessageAsString();
        $this->assertEquals(md5($testString), trim($message->getSignaturePart()->getContent()));
    }

    public function testParseEmailm4008()
    {
        $this->runEmailTest('m4008', [
            'From' => [
                'name' => 'Doug Sauder',
                'email' => 'dwsauder@example.com'
            ],
            'To' => [
                'name' => 'Heinz Müller',
                'email' => 'mueller@example.com'
            ],
            'Subject' => 'Die Hasen und die Frösche (Netscape Messenger 4.7)',
            'signed' => [
                'protocol' => 'application/x-pgp-signature',
                'signed-part-protocol' => 'application/pgp-signature',
                'micalg' => 'pgp-sha256',
                'body' => '9f5c560f86b607c9087b84e9baa98189'
            ],
        ]);
    }
}
