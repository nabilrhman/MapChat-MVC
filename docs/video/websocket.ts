/**
 * The least of a WebSocket server, for one thing: taking the recording's
 * binary messages from the capture extension (capture-extension/), as there's
 * no WebSocket package here. It answers the handshake and unmasks each
 * message; it doesn't send any.
 */
import {createHash} from 'node:crypto';
import type {Server} from 'node:http';

// The fixed string the handshake's answer is made with (RFC 6455)
const HANDSHAKE_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
const CLOSE = 8;
const BINARY = 2;
const CONTINUATION = 0;

/**
 * Hands each binary message from the first connection to `onMessage`
 *
 * @returns Settles when that connection closes, so everything sent has arrived
 */
export function receiveBinaryMessages(server: Server, onMessage: (message: Buffer) => void): Promise<void> {
    return new Promise((resolve) => {
        server.once('upgrade', (request, socket) => {
            const accept = createHash('sha1').update(request.headers['sec-websocket-key'] + HANDSHAKE_GUID).digest('base64');
            socket.write(`HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: ${accept}\r\n\r\n`);

            let unread = Buffer.alloc(0);
            let parts: Buffer[] = [];
            socket.on('data', (chunk: Buffer) => {
                unread = unread.length ? Buffer.concat([unread, chunk]) : chunk;
                for (let frame = readFrame(unread); frame; frame = readFrame(unread)) {
                    unread = unread.subarray(frame.length);
                    if (frame.opcode === CLOSE) {
                        socket.end();
                        return;
                    }
                    if (frame.opcode === BINARY || frame.opcode === CONTINUATION) {
                        parts.push(frame.payload);
                        if (frame.isFinal) {
                            onMessage(parts.length === 1 ? parts[0] : Buffer.concat(parts));
                            parts = [];
                        }
                    }
                }
            });
            socket.on('close', () => resolve());
        });
    });
}

/** The first whole frame in `bytes`, unmasked, or null until it's all arrived */
function readFrame(bytes: Buffer): {opcode: number, isFinal: boolean, payload: Buffer, length: number} | null {
    if (bytes.length < 2) {
        return null;
    }
    const isFinal = (bytes[0] & 0x80) !== 0;
    const opcode = bytes[0] & 0x0f;
    let payloadLength = bytes[1] & 0x7f;
    let offset = 2;
    if (payloadLength === 126) {
        if (bytes.length < 4) return null;
        payloadLength = bytes.readUInt16BE(2);
        offset = 4;
    } else if (payloadLength === 127) {
        if (bytes.length < 10) return null;
        payloadLength = Number(bytes.readBigUInt64BE(2));
        offset = 10;
    }
    // A browser always masks what it sends, with these 4 bytes
    const maskAt = offset;
    const payloadAt = maskAt + 4;
    if (bytes.length < payloadAt + payloadLength) {
        return null;
    }
    const mask = bytes.subarray(maskAt, payloadAt);
    const payload = Buffer.from(bytes.subarray(payloadAt, payloadAt + payloadLength));
    for (let i = 0; i < payload.length; i++) {
        payload[i] ^= mask[i % 4];
    }
    return {opcode, isFinal, payload, length: payloadAt + payloadLength};
}
