import http from 'node:http'
import { WebSocketServer } from 'ws'

const port = Number(process.env.FINOPAL_WS_PORT || 6001)
const secret = process.env.FINOPAL_WS_SECRET || 'finopal-ws-secret'
const clients = new Map()

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
      const ids = event.payload?.participant_ids ?? []
      const message = JSON.stringify(event)
      for (const [userId, sockets] of clients) {
        if (ids.length === 0 || ids.includes(Number(userId))) {
          for (const socket of sockets) {
            if (socket.readyState === 1) socket.send(message)
          }
        }
      }
      res.writeHead(200).end('ok')
    })
    return
  }
  res.writeHead(200).end('finopal-ws')
})

const wss = new WebSocketServer({ server })
wss.on('connection', (socket, req) => {
  const url = new URL(req.url ?? '/', 'http://localhost')
  const userId = url.searchParams.get('user_id') ?? '0'
  if (!clients.has(userId)) clients.set(userId, new Set())
  clients.get(userId).add(socket)
  socket.send(JSON.stringify({ event: 'presence.online', payload: { user_id: Number(userId) } }))
  socket.on('close', () => clients.get(userId)?.delete(socket))
})

server.listen(port, () => console.log(`Finopal websocket on :${port}`))
