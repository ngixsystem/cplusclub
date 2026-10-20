/** Ticket list filters as they come from the server (and go back into the URL). */
export type TicketFilters = { tab: string; priority: string[]; club: number | null; assignee: number | string | null; category: string | null; period: number | null; search: string; sort: string };
