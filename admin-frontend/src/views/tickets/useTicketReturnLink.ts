import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'

export interface TicketReturnQuery {
  ticket_return_id?: string
  ticket_return_subject?: string
}

function getQueryValue(value: unknown): string {
  if (Array.isArray(value)) {
    return typeof value[0] === 'string' ? value[0] : ''
  }

  return typeof value === 'string' ? value : ''
}

export function buildTicketReturnQuery(ticket: { id: number; subject?: string | null }): TicketReturnQuery {
  return {
    ticket_return_id: String(ticket.id),
    ticket_return_subject: ticket.subject || undefined,
  }
}

export function useTicketReturnLink() {
  const route = useRoute()
  const router = useRouter()

  const ticketReturnId = computed(() => {
    const numeric = Number(getQueryValue(route.query.ticket_return_id))
    return Number.isFinite(numeric) && numeric > 0 ? numeric : null
  })

  const ticketReturnSubject = computed(() => getQueryValue(route.query.ticket_return_subject))

  const hasTicketReturn = computed(() => ticketReturnId.value !== null)

  const ticketReturnLabel = computed(() => (
    ticketReturnSubject.value
      ? `返回工单 #${ticketReturnId.value} · ${ticketReturnSubject.value}`
      : `返回工单 #${ticketReturnId.value}`
  ))

  function returnToTicket() {
    if (!ticketReturnId.value) {
      return Promise.resolve()
    }

    return router.push({
      name: 'Tickets',
      query: {
        ticket_id: String(ticketReturnId.value),
      },
    })
  }

  return {
    hasTicketReturn,
    ticketReturnId,
    ticketReturnLabel,
    returnToTicket,
  }
}
