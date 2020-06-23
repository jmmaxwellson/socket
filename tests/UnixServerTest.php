<?php

namespace React\Tests\Socket;

use Clue\React\Block;
use React\EventLoop\Factory;
use React\Socket\UnixServer;
use React\Stream\DuplexResourceStream;

class UnixServerTest extends TestCase
{
    private $loop;
    private $server;
    private $uds;

    /**
     * @covers React\Socket\UnixServer::__construct
     * @covers React\Socket\UnixServer::getAddress
     */
    public function setUp()
    {
        if (!in_array('unix', stream_get_transports())) {
            $this->markTestSkipped('Unix domain sockets (UDS) not supported on your platform (Windows?)');
        }

        $this->loop = Factory::create();
        $this->uds = $this->getRandomSocketUri();
        $this->server = new UnixServer($this->uds, $this->loop);
    }

    /**
     * @covers React\Socket\UnixServer::handleConnection
     */
    public function testConnection()
    {
        $client = stream_socket_client($this->uds);

        $this->server->on('connection', $this->expectCallableOnce());
        $this->tick();
    }

    /**
     * @covers React\Socket\UnixServer::handleConnection
     */
    public function testConnectionWithManyClients()
    {
        $client1 = stream_socket_client($this->uds);
        $client2 = stream_socket_client($this->uds);
        $client3 = stream_socket_client($this->uds);

        $this->server->on('connection', $this->expectCallableExactly(3));
        $this->tick();
        $this->tick();
        $this->tick();
    }

    public function testDataEventWillNotBeEmittedWhenClientSendsNoData()
    {
        $client = stream_socket_client($this->uds);

        $mock = $this->expectCallableNever();

        $this->server->on('connection', function ($conn) use ($mock) {
            $conn->on('data', $mock);
        });
        $this->tick();
        $this->tick();
    }

    public function testDataWillBeEmittedWithDataClientSends()
    {
        $client = stream_socket_client($this->uds);

        fwrite($client, "foo\n");

        $mock = $this->expectCallableOnceWith("foo\n");

        $this->server->on('connection', function ($conn) use ($mock) {
            $conn->on('data', $mock);
        });
        $this->tick();
        $this->tick();
    }

    public function testDataWillBeEmittedEvenWhenClientShutsDownAfterSending()
    {
        $client = stream_socket_client($this->uds);
        fwrite($client, "foo\n");
        stream_socket_shutdown($client, STREAM_SHUT_WR);

        $mock = $this->expectCallableOnceWith("foo\n");

        $this->server->on('connection', function ($conn) use ($mock) {
            $conn->on('data', $mock);
        });
        $this->tick();
        $this->tick();
    }

    public function testLoopWillEndWhenServerIsClosed()
    {
        // explicitly unset server because we already call close()
        $this->server->close();
        $this->server = null;

        $this->loop->run();

        // if we reach this, then everything is good
        $this->assertNull(null);
    }

    public function testCloseTwiceIsNoOp()
    {
        $this->server->close();
        $this->server->close();

        // if we reach this, then everything is good
        $this->assertNull(null);
    }

    public function testGetAddressAfterCloseReturnsNull()
    {
        $this->server->close();
        $this->assertNull($this->server->getAddress());
    }

    public function testLoopWillEndWhenServerIsClosedAfterSingleConnection()
    {
        $client = stream_socket_client($this->uds);

        // explicitly unset server because we only accept a single connection
        // and then already call close()
        $server = $this->server;
        $this->server = null;

        $server->on('connection', function ($conn) use ($server) {
            $conn->close();
            $server->close();
        });

        $this->loop->run();

        // if we reach this, then everything is good
        $this->assertNull(null);
    }

    public function testDataWillBeEmittedInMultipleChunksWhenClientSendsExcessiveAmounts()
    {
        $client = stream_socket_client($this->uds);
        $stream = new DuplexResourceStream($client, $this->loop);

        $bytes = 1024 * 1024;
        $stream->end(str_repeat('*', $bytes));

        $mock = $this->expectCallableOnce();

        // explicitly unset server because we only accept a single connection
        // and then already call close()
        $server = $this->server;
        $this->server = null;

        $received = 0;
        $server->on('connection', function ($conn) use ($mock, &$received, $server) {
            // count number of bytes received
            $conn->on('data', function ($data) use (&$received) {
                $received += strlen($data);
            });

            $conn->on('end', $mock);

            // do not await any further connections in order to let the loop terminate
            $server->close();
        });

        $this->loop->run();

        $this->assertEquals($bytes, $received);
    }

    public function testConnectionDoesNotEndWhenClientDoesNotClose()
    {
        $client = stream_socket_client($this->uds);

        $mock = $this->expectCallableNever();

        $this->server->on('connection', function ($conn) use ($mock) {
            $conn->on('end', $mock);
        });
        $this->tick();
        $this->tick();
    }

    /**
     * @covers React\Socket\Connection::end
     */
    public function testConnectionDoesEndWhenClientCloses()
    {
        $client = stream_socket_client($this->uds);

        fclose($client);

        $mock = $this->expectCallableOnce();

        $this->server->on('connection', function ($conn) use ($mock) {
            $conn->on('end', $mock);
        });
        $this->tick();
        $this->tick();
    }

    public function testCtorAddsResourceToLoop()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addReadStream');

        $server = new UnixServer($this->getRandomSocketUri(), $loop);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testCtorThrowsForInvalidAddressScheme()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $server = new UnixServer('tcp://localhost:0', $loop);
    }

    /**
     * @expectedException RuntimeException
     */
    public function testCtorThrowsWhenPathIsNotWritable()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $server = new UnixServer('/dev/null', $loop);
    }

    public function testResumeWithoutPauseIsNoOp()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addReadStream');

        $server = new UnixServer($this->getRandomSocketUri(), $loop);
        $server->resume();
    }

    public function testPauseRemovesResourceFromLoop()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('removeReadStream');

        $server = new UnixServer($this->getRandomSocketUri(), $loop);
        $server->pause();
    }

    public function testPauseAfterPauseIsNoOp()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('removeReadStream');

        $server = new UnixServer($this->getRandomSocketUri(), $loop);
        $server->pause();
        $server->pause();
    }

    public function testCloseRemovesResourceFromLoop()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('removeReadStream');

        $server = new UnixServer($this->getRandomSocketUri(), $loop);
        $server->close();
    }

    public function testEmitsErrorWhenAcceptListenerFails()
    {
        $listener = null;
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addReadStream')->with($this->anything(), $this->callback(function ($cb) use (&$listener) {
            $listener = $cb;
            return true;
        }));

        $server = new UnixServer($this->getRandomSocketUri(), $loop);

        $server->on('error', $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException')));

        $this->assertNotNull($listener);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        fclose($socket);
        $listener($socket);
    }

    /**
     * @expectedException RuntimeException
     */
    public function testListenOnBusyPortThrows()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Windows supports listening on same port multiple times');
        }

        $another = new UnixServer($this->uds, $this->loop);
    }

    /**
     * @covers React\Socket\UnixServer::close
     */
    public function tearDown()
    {
        if ($this->server) {
            $this->server->close();
        }
    }

    private function getRandomSocketUri()
    {
        return "unix://" . sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid(rand(), true) . '.sock';
    }

    private function tick()
    {
        Block\sleep(0, $this->loop);
    }
}
