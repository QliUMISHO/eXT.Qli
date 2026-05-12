const BASE_PATH =
    window.EXTQLI_API_BASE_PATH ||
    window.EXTQLI_PAGE_BASE_PATH ||
    '/eXT.Qli_preprod';

const CONFIGURED_ENDPOINTS = window.EXTQLI_ENDPOINTS || {};

export const endpoints = {
    agents: CONFIGURED_ENDPOINTS.agents || `${BASE_PATH}/backend/api/agents.php`,
    rootAgents: CONFIGURED_ENDPOINTS.rootAgents || `${BASE_PATH}/agents.php`,
    saveIdentity: CONFIGURED_ENDPOINTS.saveIdentity || `${BASE_PATH}/backend/api/save_agent_identity.php`,
    deleteAgent: CONFIGURED_ENDPOINTS.deleteAgent || `${BASE_PATH}/backend/api/delete_agent.php`,
    signaling: CONFIGURED_ENDPOINTS.signaling || `${BASE_PATH}/backend/api/signaling.php`,
    taskResult: CONFIGURED_ENDPOINTS.taskResult || `${BASE_PATH}/backend/api/agent_task_result.php`,
    deprecatedTask: CONFIGURED_ENDPOINTS.deprecatedTask || `${BASE_PATH}/backend/api/send_agent_task.php`,
    heartbeat: CONFIGURED_ENDPOINTS.heartbeat || `${BASE_PATH}/backend/api/agent_heartbeat.php`,
    headlessNativeCommand: CONFIGURED_ENDPOINTS.headlessNativeCommand || `${BASE_PATH}/backend/api/headless_native_command.php`,
    nativeCommandPoll: CONFIGURED_ENDPOINTS.nativeCommandPoll || `${BASE_PATH}/backend/api/agent_native_command_poll.php`,
    nativeCommandDone: CONFIGURED_ENDPOINTS.nativeCommandDone || `${BASE_PATH}/backend/api/agent_native_command_done.php`
};

async function parseJsonResponse(response) {
    const text = await response.text();

    let data = {};

    try {
        data = text ? JSON.parse(text) : {};
    } catch (error) {
        data = {
            success: false,
            message: `Invalid JSON response from ${response.url}`,
            raw: text
        };
    }

    if (!response.ok && data && typeof data === 'object') {
        data.http_status = response.status;
    }

    return data;
}

export async function getJson(url, options = {}) {
    const response = await fetch(url, {
        method: 'GET',
        headers: {
            Accept: 'application/json',
            ...(options.headers || {})
        },
        cache: options.cache || 'no-store',
        ...options
    });

    return parseJsonResponse(response);
}

export async function postJson(url, payload, options = {}) {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            ...(options.headers || {})
        },
        body: JSON.stringify(payload || {}),
        cache: options.cache || 'no-store',
        ...options
    });

    return parseJsonResponse(response);
}

export function apiUrl(name) {
    return endpoints[name] || '';
}