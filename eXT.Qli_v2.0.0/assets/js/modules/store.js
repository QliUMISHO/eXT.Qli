export const appState = {
    agents: [],
    selectedAgentUuid: '',
    currentScreenAgentUuid: '',
    currentAdminTab: 'agents',
    isScreenViewing: false,
    remoteControlEnabled: false,
    viewerPeers: {},
    previewStreams: {},
    mainStream: null,
    autoConnect: {
        enabled: true,
        queue: [],
        active: false,
        cancelled: false
    }
};

export function resetAutoConnectQueue() {
    appState.autoConnect.queue.length = 0;
    appState.autoConnect.active = false;
    appState.autoConnect.cancelled = false;
}
