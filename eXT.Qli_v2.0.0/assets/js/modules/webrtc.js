import { appState } from './store.js';

export function getPeer(agentUuid) {
    return appState.viewerPeers[String(agentUuid || '')] || null;
}

export function isPeerAlive(peer) {
    if (!peer || !peer.pc) return false;
    return peer.pc.connectionState === 'connected' || peer.pc.connectionState === 'connecting';
}

export function closePeer(agentUuid) {
    const key = String(agentUuid || '');
    const peer = appState.viewerPeers[key];
    if (!peer) return;

    try {
        if (peer.answerPollInterval) clearInterval(peer.answerPollInterval);
    } catch (_) {}

    try {
        if (peer.pc) peer.pc.close();
    } catch (_) {}

    delete appState.viewerPeers[key];
    delete appState.previewStreams[key];
}
