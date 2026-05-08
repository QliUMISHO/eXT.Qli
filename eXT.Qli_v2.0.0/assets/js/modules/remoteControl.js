export function sendDataChannelInput(peer, payload) {
    if (!peer || !peer.dataChannel || peer.dataChannel.readyState !== 'open') {
        return false;
    }
    peer.dataChannel.send(JSON.stringify(payload));
    return true;
}
