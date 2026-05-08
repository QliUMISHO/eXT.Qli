import { appState } from './store.js';

export function setPreviewStream(agentUuid, stream) {
    appState.previewStreams[String(agentUuid || '')] = stream;
}

export function clearPreviewStream(agentUuid) {
    delete appState.previewStreams[String(agentUuid || '')];
}

export function setMainStream(stream) {
    appState.mainStream = stream || null;
}
