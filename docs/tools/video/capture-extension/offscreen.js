// Records the captured tab with Chrome's real-time VP8 encoder, which keeps up
// with a Retina-sized tab at 60fps (H.264 here is software and manages about
// 26), and streams the file out over a WebSocket as it's made. The tour script
// turns it into the final H.264 on the GPU

let recorder = null;
let socket = null;

function startRecording({streamId, socketUrl, width, height, bitsPerSecond}) {
    const size = {minWidth: width, maxWidth: width, minHeight: height, maxHeight: height, minFrameRate: 60, maxFrameRate: 60};
    return navigator.mediaDevices.getUserMedia({video: {mandatory: {chromeMediaSource: 'tab', chromeMediaSourceId: streamId, ...size}}})
        .then(async (stream) => {
            socket = new WebSocket(socketUrl);
            socket.binaryType = 'arraybuffer';
            await new Promise((resolve, reject) => {
                socket.onopen = resolve;
                socket.onerror = reject;
            });
            recorder = new MediaRecorder(stream, {mimeType: 'video/webm;codecs=vp8', videoBitsPerSecond: bitsPerSecond});
            // Each piece is sent in the order it's made
            let sending = Promise.resolve();
            recorder.ondataavailable = (event) => {
                sending = sending.then(async () => socket.send(await event.data.arrayBuffer()));
            };
            recorder.sending = () => sending;
            recorder.start(500);
            return {ok: true, settings: stream.getVideoTracks()[0].getSettings()};
        })
        .catch((error) => ({ok: false, error: String(error)}));
}

async function stopRecording() {
    await new Promise((resolve) => {
        recorder.onstop = resolve;
        recorder.stream.getTracks().forEach((track) => track.stop());
        recorder.stop();
    });
    await recorder.sending();
    while (socket.bufferedAmount > 0) {
        await new Promise((resolve) => setTimeout(resolve, 20));
    }
    socket.close();
    return {ok: true};
}

chrome.runtime.onMessage.addListener((message, _sender, reply) => {
    if (message.type === 'start') {
        startRecording(message).then(reply);
        return true;
    }
    if (message.type === 'stop') {
        stopRecording().then(reply);
        return true;
    }
});
