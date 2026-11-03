/**
 * Whether the signed-in user may change a gateway: its routes, consumers, plans, domain and
 * developer page (GatewayPolicy::update), as the API says (GatewayResource can_manage). A gateway
 * without the field keeps its controls: the API decides. A route also needs projects.update on its
 * project (GatewayRoutes). The API enforces both; the console only hides and disables what the
 * user cannot do.
 */
export const canManageGateway = (gateway) => gateway?.can_manage !== false
