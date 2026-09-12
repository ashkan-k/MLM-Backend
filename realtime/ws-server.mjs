import http from 'node:http'
import { WebSocketServer } from 'ws'

const port = Number(process.env.FINOPAL_WS_PORT || 6001)
const secret = process.env.FINOPAL_WS_SECRET || 'finopal-ws-secret'
const api = process.env.FINOPAL_API_URL || 'http://127.0.0.1:8000'
const clients = new Map()

function sendTo(ids, message) {
  const payload = typeof message === 'string' ? message : JSON.stringify(message)
  for (const [userId, sockets] of clients) {
    if (ids.length === 0 || ids.includes(Number(userId))) {
      for (const socket of sockets) {
        if (socket.readyState === 1) socket.send(payload)
      }
    }
  }
}

const server = http.createServer((req, res) => {
  if (req.method === 'POST' && req.url === '/broadcast') {
    if (req.headers['x-ws-secret'] !== secret) {
      res.writeHead(401).end('forbidden')
      return
    }
    let body = ''
    req.on('data', (c) => { body += c })
    req.on('end', () => {
      const event = JSON.parse(body || '{}')
      sendTo(event.payload?.participant_ids ?? [], event)
      res.writeHead(200).end('ok')
    })
    return
  }
  res.writeHead(200).end('finopal-ws')
})

async function persistChat(token, conversationId, body) {
  const res = await fetch(`${api}/api/conversations/${conversationId}/messages`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ body }),
  })
  if (!res.ok) throw new Error(await res.text())
  return res.json()
}

const wss = new WebSocketServer({ server })
wss.on('connection', (socket, req) => {
  const url = new URL(req.url ?? '/', 'http://localhost')
  const userId = url.searchParams.get('user_id') ?? '0'
  if (!clients.has(userId)) clients.set(userId, new Set())
  clients.get(userId).add(socket)
  socket.send(JSON.stringify({ event: 'presence.online', payload: { user_id: Number(userId) } }))
  socket.on('message', async (raw) => {
    try {
      const data = JSON.parse(String(raw))
      if (data.action === 'send' && data.token && data.conversation_id) {
        const message = await persistChat(data.token, data.conversation_id, data.body)
        socket.send(JSON.stringify({
          event: 'message.sent',
          payload: { conversation_id: data.conversation_id, message },
        }))
        return
      }
      if (data.action === 'typing' && data.conversation_id) {
        sendTo([], {
          event: data.started === false ? 'typing.stopped' : 'typing.started',
          payload: { conversation_id: data.conversation_id, user_id: Number(userId) },
        })
      }
    } catch (error) {
      socket.send(JSON.stringify({ event: 'chat.error', payload: { message: String(error?.message ?? error) } }))
    }
  })
  socket.on('close', () => clients.get(userId)?.delete(socket))
})

server.listen(port, () => console.log(`Finopal websocket on :${port}`))
