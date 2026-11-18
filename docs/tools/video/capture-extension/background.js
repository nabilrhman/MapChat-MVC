// The tour script starts and stops the recording through this service worker.
// Capturing a tab needs a stream id from here, and recording it needs a page,
// so the recording itself runs in an offscreen document (offscreen.js)

// Records the tab whose address starts with `appUrl`, at `width` × `height`,
// streaming the file to the WebSocket at `socketUrl`
self.startRecording = async (appUrl, socketUrl, width, height, bitsPerSecond) => {
    const [tab] = await chrome.tabs.query({url: `${appUrl}/*`});
    const streamId = await chrome.tabCapture.getMediaStreamId({targetTabId: tab.id});
    if (!(await chrome.offscreen.hasDocument())) {
        await chrome.offscreen.createDocument({url: 'offscreen.html', reasons: ['USER_MEDIA'], justification: 'Record the tour'});
    }
    return chrome.runtime.sendMessage({type: 'start', streamId, socketUrl, width, height, bitsPerSecond});
};

// Stops it; answers once the whole file has gone out
self.stopRecording = () => chrome.runtime.sendMessage({type: 'stop'});
